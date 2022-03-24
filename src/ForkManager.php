<?php

namespace Async;

use Async\Exception\ForkException;
use Async\Socket\Server;

class ForkManager {
  const FORK_WAIT_SLEEP = 2;
  const FORK_WAIT_TIMEOUT = 600;
  protected array $forks = [];
  protected Server $server;
  protected bool $async = true;


  public function __construct()
  {
    $this->async = function_exists('pcntl_fork');
  }

  /**
   * Create a new instance of ForkInterface.
   */
  public function create():ForkInterface
  {
    if ($this->async && !isset($this->server)) {
      $this->server = new Server();
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
   * Await for async forks to complete.
   *
   * This is a blocking function.
   */
  public function awaitForks():ForkManager
  {
    $start = time();
    do {
      $awaiting = array_filter($this->forks,
        fn($f) => !in_array($f->getStatus(), [
          ForkInterface::STATUS_COMPLETE,
          ForkInterface::STATUS_ERROR
        ])
      );

      if ((time() - $start) > self::FORK_WAIT_TIMEOUT) {
        throw new ForkException(self::class." has timed out waiting for forks to complete:\n- ".implode("\n- ",
          array_map(fn($f) => $f->getLabel(), $awaiting)
        ));
      }

      $this->readFromServer(count($awaiting));
    }
    while (!empty($awaiting));
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
   */
  public function getForks():array
  {
    return $this->forks;
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
}
