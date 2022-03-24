<?php

namespace Async;

interface ForkInterface {
  const STATUS_NOTSTARTED = 3;
  const STATUS_INPROGRESS = 5;
  const STATUS_COMPLETE = 7;
  const STATUS_ERROR = 11;

  /**
   * The forked routine to run.
   */
  public function run(callable $callback):ForkInterface;

  /**
   * A callback to call when fork is successful.
   */
  public function onSuccess(\Closure $callback):ForkInterface;

  /**
   * A callback to call when fork has errored.
   */
  public function onError(\Closure $callback):ForkInterface;

  /**
   * Set the resulting output of the run() call.
   */
  public function setResult($result):ForkInterface;

  /**
   * Retrieve the result.
   */
  public function getResult();

  /**
   * Update the status for the fork routine.
   */
  public function setStatus(int $status):ForkInterface;

  /**
   * Retrieve the current status.
   */
  public function getStatus():int;

  /**
   * Set a label for the fork.
   */
  public function setLabel(string $title):ForkInterface;

  /**
   * The the fork label.
   */
  public function getLabel():string;

  /**
   * Set an ID unique to the ForkManager.
   */
  public function setId(int $id):ForkInterface;

  /**
   * Get the unique ID.
   */
  public function getId():int;
}
