<?php

namespace Async;

use Async\Exception\ForkException;
use Async\Exception\ChildExceptionDetected;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerTrait;


class SynchronousFork implements ForkInterface {

  use LoggerAwareTrait;
  use LoggerTrait;

  protected int $status = 1;
  protected \Closure $runCallback;
  protected \Closure $onSuccessCallback;
  protected \Closure $onErrorCallback;
  protected $result;
  protected bool $processed = false;
  protected string $label;
  protected int $id;
  protected ForkManager $forkManager;
  protected int $startTime;
  protected int $finishTime;

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

    if ($this->status != ForkInterface::STATUS_INPROGRESS) {
      return $this->status;
    }

    // If the fork is in progress but has elapsed the timeout period the we
    // will error out the fork.
    $elapsed = time() - $this->startTime;
    if ($elapsed > $this->forkManager->getWaitTimeout()) {
      $this->setStatus(ForkInterface::STATUS_ERROR);
      $message = sprintf("Fork '%s' timed out after %d seconds.", $this->label, $this->forkManager->getWaitTimeout());
      $this->error($message);
      $e = new ForkException($message);
      $this->setResult(new ChildExceptionDetected($e));
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
    $this->startTime = time();
    $this->status = ForkInterface::STATUS_INPROGRESS;
    try {
      $callback = $this->runCallback;
      $result = $callback($this);
      $this->status = ForkInterface::STATUS_COMPLETE;
      $this->finishTime = time();
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
    // Because exceptions can't be reliably serialized, we turn them
    // into ChildExceptionDetected instances here. This also ensures the type
    // of object passed to onError handlers is consistent.
    if ($result instanceof \Exception) {
      $result = new ChildExceptionDetected($result);
    }

    $this->result = $result;
    if ($this->status == self::STATUS_ERROR && isset($this->onErrorCallback)) {
      $callback = $this->onErrorCallback;
    }
    elseif ($this->status == self::STATUS_COMPLETE && isset($this->onSuccessCallback)) {
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

  /**
   * {@inheritdoc}
   */
  public function log($level, $message, array $context = array()) {
    if (!isset($this->logger)) {
      return;
    }
    return $this->logger->log($level, get_class($this).'('.getmypid().'): '.$message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function getStartTime(): int
  {
    return $this->startTime;
  }

  /**
   * {@inheritdoc}
   */
  public function getFinishTime(): int
  {
    return $this->finishTime;
  }

  /**
   * {@inheritdoc}
   */
  public function getElapsedTime(): int
  {
    return $this->finishTime - $this->startTime;
  }
}
