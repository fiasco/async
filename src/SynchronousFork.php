<?php

namespace Async;

use Async\Exception\ForkException;
use Psr\Log\LoggerAwareTrait;


class SynchronousFork implements ForkInterface {

  use LoggerAwareTrait;

  protected int $status = 1;
  protected \Closure $runCallback;
  protected \Closure $onSuccessCallback;
  protected \Closure $onErrorCallback;
  protected $result;
  protected bool $processed = false;
  protected string $label;
  protected int $id;
  protected ForkManager $forkManager;

  public function __construct(ForkManager $forkManager)
  {
    $this->label = sprintf("%s %s", static::class, mt_rand(10000, 99999));
    $this->status = ForkInterface::STATUS_NOTSTARTED;
    $this->forkManager = $forkManager;
  }

  /**
   * {@inheritdoc}
   */
  public function setLabel(string $label):ForkInterface
  {
    $this->label = $label;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel():string
  {
    return $this->label;
  }

  /**
   * {@inheritdoc}
   */
  public function setId(int $id):ForkInterface
  {
    $this->id = $id;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getId():int
  {
    return $this->id;
  }

  /**
   * {@inheritdoc}
   */
  public function setStatus(int $status):ForkInterface
  {
    switch ($status) {
      case ForkInterface::STATUS_NOTSTARTED:
      case ForkInterface::STATUS_INPROGRESS:
      case ForkInterface::STATUS_COMPLETE:
      case ForkInterface::STATUS_ERROR:
        $this->status = $status;
        break;
      default:
        throw new ForkException("Invalid status provided: $status.");
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus():int
  {
    // Ensure onSuccess and onError callbacks have had a chance to run.
    if (!$this->processed && isset($this->result)) {
      return $this->setResult($this->result)->getStatus();
    }
    return $this->status;
  }

  /**
   * {@inheritdoc}
   */
  public function run(\Closure $callback):ForkInterface
  {
    $this->runCallback = $callback;
    $this->forkManager->updateForkStatus();
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function execute():ForkInterface
  {
    $this->status = ForkInterface::STATUS_INPROGRESS;
    try {
      $callback = $this->runCallback;
      $result = $callback($this);
      $this->status = ForkInterface::STATUS_COMPLETE;
      return $this->setResult($result);
    }
    catch (\Exception $e) {
      $this->status = ForkInterface::STATUS_ERROR;
      return $this->setResult($e);
    }
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
  public function setResult($result):ForkInterface
  {
    $this->result = $result;
    if ($this->status == self::STATUS_ERROR && isset($this->onErrorCallback)) {
      $callback = $this->onErrorCallback;
    }
    if ($this->status == self::STATUS_COMPLETE && isset($this->onSuccessCallback)) {
      $callback = $this->onSuccessCallback;
    }
    if (isset($callback)) {
      $callback($result, $this);
    }
    $this->processed = true;
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
  public function terminate():ForkInterface
  {
    // Synchronous forks cannot terminate.
    return $this;
  }
}
