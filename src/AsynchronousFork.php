<?php

namespace Async;

use Async\Exception\ForkException;
use Async\Exception\ChildExceptionDetected;

class AsynchronousFork extends SynchronousFork implements \Serializable {

  const ROLE_CLIENT = 'client';
  const ROLE_SERVER = 'server';

  protected string $role;
  protected int $pid;

  public function __construct(ForkManager $forkManager)
  {
    parent::__construct($forkManager);
    $this->label = sprintf("%s %s", static::class, getmypid());
  }

  /**
   * {@inheritdoc}
   */
  public function terminate():ForkInterface
  {
    // Kill forks we're still awaiting to complete.
    if (isset($this->pid)) {
      posix_kill($this->pid, SIGKILL);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus():int
  {
    // Do not run event handers only client.
    if ($this->role == self::ROLE_CLIENT) {
      return $this->status;
    }
    return parent::getStatus();
  }

  /**
   * {@inheritdoc}
   */
  public function run(\Closure $callback):ForkInterface
  {
    $this->status = ForkInterface::STATUS_INPROGRESS;
    $pid = pcntl_fork();
    // Child thread gets a zero, the other thread is the parent.
    $this->role = ($pid == 0) ? self::ROLE_CLIENT : self::ROLE_SERVER;

    if ($this->role == self::ROLE_SERVER) {
      $this->pid = $pid;
      return $this;
    }

    // Because we're asynchronous we don't have to wait for
    // execute() to be called.

    // Don't run these in the fork.
    unset($this->onError, $this->onSuccess);

    $client = $this->forkManager->getServer()->getClient();

    set_exception_handler(function ($e) use ($client) {
      if (isset($this->logger)) {
        $this->logger->error($e->getMessage());
      }
      $this->setResult(new ChildExceptionDetected($e));
      $this->setStatus(ForkInterface::STATUS_ERROR);
      $client->send($this);
    });

    $result = call_user_func($callback, $this);
    $this->status = ForkInterface::STATUS_COMPLETE;
    $this->setResult($result);
    $client->send($this);
    exit;
  }

  /**
   * {@inheritdoc}
   */
  public function execute():ForkInterface
  {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function onSuccess(\Closure $callback):ForkInterface
  {
    $this->processed = false;
    $this->onSuccessCallback = $callback;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function onError(\Closure $callback):ForkInterface
  {
    $this->processed = false;
    $this->onErrorCallback = $callback;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getResult()
  {
    return $this->result;
  }

  /**
   * {@inheritdoc}
   */
  public function serialize()
  {
    return serialize([
      'id' => $this->id,
      'result' => $this->result ?? null,
      'label' => $this->label,
      'status' => $this->status,
      'role' => $this->role
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function unserialize($data)
  {
    $attributes = unserialize($data);
    $this->id  = $attributes['id'];
    $this->result = $attributes['result'];
    $this->label = $attributes['label'];
    $this->status = $attributes['status'];
    $this->role = $attributes['role'];

    // ChildExceptionDetected cannot be an Exception class because serialization
    // of Closures cause fatal errors.
    if ($this->result instanceof ChildExceptionDetected) {
      $this->result = new ForkException((string) $this->result);
    }
  }
}
