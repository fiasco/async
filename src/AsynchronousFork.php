<?php

namespace Async;

use Async\Exception\ForkException;
use Async\Exception\ChildExceptionDetected;
use Async\React\Client;

class AsynchronousFork extends SynchronousFork implements \Serializable {

  const ROLE_CHILD = 'child';
  const ROLE_PARENT = 'parent';

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
    // Forking hasn't been initiated yet.
    if (!isset($this->role)) {
      return parent::getStatus();
    }
    // Do not run event handers only client.
    if ($this->role == self::ROLE_CHILD) {
      return $this->status;
    }
    if ($this->status != ForkInterface::STATUS_INPROGRESS) {
      return parent::getStatus();
    }

    $message = Client::get('/fork/'.$this->pid);
    if ($message->getMethod() != 'HIT') {
      return parent::getStatus();
    }

    $fork = $message->getPayload();
    $this->setLabel($fork->getLabel())
         ->setStatus($fork->getStatus())
         ->setResult($fork->getResult());

    return parent::getStatus();
  }

  /**
   * {@inheritdoc}
   */
  public function execute():ForkInterface
  {
    $this->status = ForkInterface::STATUS_INPROGRESS;
    $pid = pcntl_fork();
    // Child thread gets a zero, the other thread is the parent.
    $this->role = ($pid == 0) ? self::ROLE_CHILD : self::ROLE_PARENT;


    if ($this->role == self::ROLE_PARENT) {
      $this->pid = $pid;
      $this->label = sprintf("%s %s", static::class, $this->pid);
      return $this;
    }

    $this->pid = getmypid();
    $this->label = sprintf("%s %s", static::class, $this->pid);

    // Don't run these in the fork.
    unset($this->onError, $this->onSuccess);

    set_exception_handler(function ($e) {
      if (isset($this->logger)) {
        $this->logger->error($e->getMessage());
      }
      $this->setResult(new ChildExceptionDetected($e));
      $this->setStatus(ForkInterface::STATUS_ERROR);

      Client::put('/fork/'.$this->pid, $this);
    });

    parent::execute();
    Client::put('/fork/'.$this->pid, $this);
    exit;
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
