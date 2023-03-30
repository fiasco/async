<?php

namespace Async;

use Async\Exception\ForkException;
use Async\React\Server;
use Async\React\Client;
use Exception;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

function_exists('pcntl_async_signals') && pcntl_async_signals(TRUE);

class ForkManager {
  use LoggerAwareTrait;

  protected array $forks = [];
  protected Server $server;
  protected bool $async = true;
  protected int $maxParallel = 7;
  protected int $maxWaitTimeout = 600;


  public function __construct()
  {
    $this->async = function_exists('pcntl_fork');
    $this->logger = new NullLogger();
    Signal::register([Signal::SIGINT], function () {
        $this->terminateForks();
    });

    $pid = getmypid();

    register_shutdown_function(fn() => $pid == getmypid() && $this->terminateForks());
  }

  public function clearForks():void
  {
    $this->forks = [];
  }

  /**
   * Create a new instance of ForkInterface.
   */
  public function create():ForkInterface
  {
    if ($this->async) {
      Server::spawn($this->logger);
      Client::setLogger($this->logger);
    }
    $fork = $this->async ? new AsynchronousFork($this) : new SynchronousFork($this);
    $fork->setLogger($this->logger);

    $this->forks[] = $fork;
    $fork->setId(count($this->forks));
    return $fork;
  }

  /**
   * Function to disable or enable asynchronous function.
   *
   * Depends on the pcntl extension.
   */
  public function setAsync(bool $value):ForkManager
  {
    $this->async = function_exists('pcntl_fork') && $value;
    return $this;
  }

  public function isAsync():bool
  {
    return $this->async;
  }

  /**
   * Set the maximum parallel async forks to run.
   */
  public function setMaxForks(int $max):ForkManager
  {
    $this->maxParallel = $max;
    return $this;
  }

  /**
   * Set the maximum time for forks to run in seconds.
   */
  public function setWaitTimeout(int $ttl):ForkManager
  {
    $this->maxWaitTimeout = $ttl;
    return $this;
  }

  /**
   * Get the max wait timeout per fork.
   */
  public function getWaitTimeout():int
  {
    return $this->maxWaitTimeout;
  }

  /**
   * Await for async forks to complete.
   *
   * This is a blocking function.
   *
   * @param int $wait_ms number of milliseconds to wait between checks.
   */
  public function awaitForks(int $wait_ms = 100):ForkManager
  {
    while ($this->updateForkStatus() !== 0) {
        // Convert milliseconds to microseconds.
        usleep($wait_ms * 1000);
    }
    return $this;
  }

  /**
   * Provide regular updates on remaining forks while awaiting forks to finish.
   *
   * This is a blocking function.
   *
   * @param int $wait_ms number of milliseconds to wait between checks.
   */
  public function waitWithUpdates(int $wait_ms = 100):\Generator
  {
    $remaining = $this->updateForkStatus();
    while ($remaining) {
      yield $remaining;
      usleep($wait_ms * 1000);
      $remaining = $this->updateForkStatus();
    }
  }

  /**
   * Get the number of forks remaining to complete.
   *
   * This function will also execute forks awaiting to be started.
   */
  public function updateForkStatus():int
  {
    $not_started = $this->getForks(ForkInterface::STATUS_NOTSTARTED);
    $in_progress = $this->getForks(ForkInterface::STATUS_INPROGRESS);

    if (empty($not_started)) {
      return count($in_progress);
    }

    // While in progress is empty or in progress is less than the maxParallel
    // and there are forks to start, do loop.
    while ((empty($in_progress) || (count($in_progress) < $this->maxParallel)) && !empty($not_started)) {
      $fork = array_shift($not_started);
      $fork->execute();
      $in_progress = $this->getForks(ForkInterface::STATUS_INPROGRESS);
    }

    return count($in_progress) + count($not_started);
  }

  /**
   * Get all results from forks.
   *
   * @param bool $include_errors if true will include errored output.
   */
  public function getForkResults(bool $include_errors = false):array
  {
    $this->awaitForks();
    $forks = array_filter($this->forks, function ($f) use ($include_errors) {
      return $include_errors || ($f->getStatus() == ForkInterface::STATUS_COMPLETE);
    });
    return array_map(fn($f) => $f->getResult(), $forks);
  }

  /**
   * Get all forks managed by ForkManager instance.
   *
   * @param int $status see ForkInterface STATUS constants.
   */
  public function getForks(?int $status = null):array
  {
    return array_filter($this->forks, function ($fork) use ($status) {
      return is_null($status) || $fork->getStatus() == $status;
    });
  }

  /**
   * Load a fork by its ID.
   */
  public function getForkById($id):ForkInterface
  {
    $forks = array_filter($this->forks, function ($f) use ($id) {
      return $f->getId() == $id;
    });
    if (empty($forks)) {
      throw new ForkException("No such Fork: $id");
    }
    return array_shift($forks);
  }

  /**
   * Terminate all forks.
   */
  protected function terminateForks():ForkManager
  {
     foreach ($this->getForks() as $fork) {
       $fork->terminate();
     }
     function_exists('pcntl_signal_dispatch') && pcntl_signal_dispatch();
     return $this;
  }

  /**
   * Boolean indication if the manager contains errored forks.
   */
  public function hasErrors():bool
  {
    return !empty($this->getForks(ForkInterface::STATUS_ERROR));
  }
}
