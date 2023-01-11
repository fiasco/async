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
  public function run(\Closure $callback):ForkInterface;

  /**
   * Called at the time to run the run() callable.
   */
  public function execute():ForkInterface;

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

  /**
   * Terminate the concurrent processing of the fork.
   */
  public function terminate():ForkInterface;

  /**
   * Get the epoch when the fork started.
   */
  public function getStartTime():int;

  /**
   * Get the epoch when the fork completed.
   */
  public function getFinishTime():int;
  
  /**
   * Get the duration in seconds the fork took.
   */
  public function getElapsedTime():int;
}
