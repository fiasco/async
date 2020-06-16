<?php

namespace Async;

use Psr\Log\LoggerInterface;

function_exists('pcntl_async_signals') && pcntl_async_signals(TRUE);

class AsyncRuntime {
  use LoggerTrait;

  const FORK_ERROR = -1;
  const FORKED_THREAD = 0;

  protected $threads = [];
  protected $maxThreads = 7;
  public $errors = [];

  protected $logger;

  protected $pid;
  protected $parentPid;
  protected $childPid;

  /* Used when pcntl isn't working */
  protected $errorQueue = [];
  protected $queue = [];

  protected $disabled = false;

  public function __construct(LoggerInterface $logger = null)
  {
      $this->logger = $logger;
      $this->parentPid = getmypid();
      $this->pid = getmypid();

      Signal::register([Signal::SIGINT], function () {
        $this->killForks();
      });

      register_shutdown_function([$this, 'killForks']);
  }

  public function killForks()
  {
    if (!$this->isParent()) {
      return $this;
    }

    foreach (array_keys($this->threads) as $pid) {
        posix_kill($pid, SIGKILL);
        pcntl_signal_dispatch();
    }

    if (!count($this->threads)) {
      return $this;
    }

    $this->logger->warning(getmypid() . " killing " . count($this->threads) . " threads.");
    foreach (array_keys($this->threads) as $pid) {
      $this->logger->warning(getmypid() . " waiting for $pid to complete.");
      pcntl_waitpid($pid, $status);
      unset($this->threads[$pid]);
    }
    return $this;
  }

  public function __destruct()
  {
      $this->killForks();
  }

  public static function factory(LoggerInterface $logger = null)
  {
    return new static($logger);
  }

  /**
   * Attempt to run a function asynchronously.
   */
  public function run(callable $func)
  {
      if (count($this->threads) >= $this->maxThreads) {
        $this->queue[] = $func;
        return true;
      }
      return $this->fork($func);
  }

  protected function fork(callable $func)
  {
    if ($this->disabled) {
      $this->errorQueue[] = $func;
      return false;
    }

    // Create a temporary file for the forked thread to write serialized
    // output back to the parent process.
    $channel = new Channel($this->logger);

    // Create the fork or run the callable $func in the same thread using the queue.
    $this->childPid = function_exists('pcntl_fork') ? pcntl_fork() : static::FORK_ERROR;
    $this->pid = getmypid();
    if ($this->childPid == static::FORK_ERROR) {
      $this->errorQueue[] = $func;
      return false;
    }

    // If this is the parent thread, we'll have the child pid value.
    // Store the pid information in the parent thread. This conditional
    // will return false for child threads.
    if ($this->childPid !== static::FORKED_THREAD) {
      $this->threads[$this->childPid] = [$channel, $func];
      return $this->childPid;
    }

    // Only the forked thread will run this. Since pcntl_fork() returns
    // static::FORKED_THREAD to the child, we need to set the childPid
    // based on $pid.
    $this->childPid = $this->pid;
    Signal::create(null, $this->logger);

    // Using the channel returns the output to the parent.
    $this->log("Sending work to the channel.");
    $channel->send($func());
    // Job of the fork completed. We can exit now.
    $channel->waitForClearChannel()->close();
    $this->log("Fork completed. Exiting..");
    exit;
  }

  /**
   * Fork threads from the queue under maxThread limitations.
   */
  protected function workQueue()
  {
      while ((count($this->threads) < $this->maxThreads) && !empty($this->queue)) {
        $this->fork(array_shift($this->queue));
      }
  }

  /**
   * Wait for the responses and return their results.
   */
  public function wait():iterable
  {
    $total = count($this->threads);
    while (!empty($this->threads) || !empty($this->queue)) {
        $this->workQueue();

        reset($this->threads);

        $this->log(sprintf("There are still %s/%s threads active.", count($this->threads), $total));

        // Unload a thread pid.
        while ($pid = key($this->threads)) {

          $channel = $this->threads[$pid][0];
          if (!$channel->isOpen()) {
            unset($this->threads[$pid]);
            next($this->threads);
          }

          foreach ($channel->read() as $id => $response) {
              yield $response;
          }

          $child_pid = pcntl_waitpid($pid, $status, WNOHANG);

          if ($child_pid === $pid) {
              unset($this->threads[$pid]);
          }

          $sigterm = pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : FALSE;

          if ($sigterm !== SIGSEGV) {
            next($this->threads);
            continue;
          }

          $this->errorQueue[] = current($this->threads[1]);
          $channel->close();

          trigger_error("PCNTL fork ($pid) segfaulted. Regressing callback to synchronous process.");
          unset($this->threads[$pid]);
          next($this->threads);
        }
        // Sleep for 50ms before checking again.
        usleep(50000);
    }

    // For callables whose fork attempts failed, run in the parent thread
    // synchronously.
    while ($error = array_shift($this->errorQueue)) {
      yield $error();
    }
  }

  public function isParent()
  {
      return $this->parentPid == $this->pid;
  }

  public function disable()
  {
    $this->disabled = true;
    return $this;
  }

  public function enable()
  {
    $this->disabled = false;
    return $this;
  }

  public function setEnabled($state = true)
  {
    $this->disabled = !$state;
    return $this;
  }

  public function setMaxThreads(int $max)
  {
    $this->maxThreads = $max;
    return $this;
  }
}
