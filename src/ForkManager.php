<?php

namespace Async;

use Async\Exception\ForkException;
use Async\Socket\Server;
use Psr\Log\LoggerAwareTrait;


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
    Signal::register([Signal::SIGINT], function () {
        $this->terminateForks();
    });
  }

  /**
   * Create a new instance of ForkInterface.
   */
  public function create():ForkInterface
  {
    if ($this->async && !isset($this->server)) {
      $this->server = new Server();
      if (isset($this->logger)) {
        $this->server->setLogger($this->logger);
      }
    }
    $fork = $this->async ? new AsynchronousFork($this) : new SynchronousFork($this);
    $this->forks[] = $fork;
    $fork->setId(count($this->forks));
    return $fork;
  }

  /**
   * Fetch the \Async\Socket\Server object.
   *
   * This will cause a fatal error if $this->async is false.
   */
  public function getServer():Server
  {
    return $this->server;
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
   * Await for async forks to complete.
   *
   * This is a blocking function.
   */
  public function awaitForks():ForkManager
  {
    $start = time();
    $loops = 0;
    do {
      $not_started = $this->getForks(ForkInterface::STATUS_NOTSTARTED);
      $in_progress = $this->getForks(ForkInterface::STATUS_INPROGRESS);

      if ((time() - $start) > $this->maxWaitTimeout) {
        throw new ForkException(self::class." has timed out waiting for forks to complete:\n- ".implode("\n- ",
          array_map(fn($f) => $f->getLabel(), $in_progress)
        ));
      }

      $this->readFromServer(count($in_progress) + count($not_started))
           ->processQueue();

    }
    while ((count($in_progress) + count($not_started)) > 0);
    return $this;
  }

  public function processQueue():ForkManager
  {
    $not_started = $this->getForks(ForkInterface::STATUS_NOTSTARTED);
    $in_progress = $this->getForks(ForkInterface::STATUS_INPROGRESS);

    if (empty($not_started)) {
      return $this;
    }
    // While in progress is empty or in progress is less than the maxParallel
    // and there are forks to start, do loop.
    while ((empty($in_progress) || (count($in_progress) < $this->maxParallel)) && !empty($not_started)) {
      $fork = array_shift($not_started);
      $fork->execute();
      $in_progress = $this->getForks(ForkInterface::STATUS_INPROGRESS);
    }

    return $this;
  }

  /**
   * If applicable, check the server for fork messages.
   */
  protected function readFromServer(int $remaining):ForkManager
  {
    if (!isset($this->server) || !$remaining) {
      return $this;
    }
    $fork_c = $this->server->receive()->payload();

    if (!$fork_c instanceof ForkInterface) {
      throw new ForkException("Recieved non-Process payload from async server.");
    }

    $fork_s = $this->getForkById($fork_c->getId());

    // Set the status and result from the client to the fork representation
    // here in the parent thread.
    $fork_s->setLabel($fork_c->getLabel())
           ->setStatus($fork_c->getStatus())
           ->setResult($fork_c->getResult());

    return $this;
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
}
