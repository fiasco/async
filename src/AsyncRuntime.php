<?php

namespace Async;

function_exists('pcntl_async_signals') && pcntl_async_signals(TRUE);

class AsyncRuntime {
  const FORK_ERROR = -1;
  const FORKED_THREAD = 0;
  protected $threads = [];
  protected $maxThreads = 10;
  public $errors = [];

  /* Used when pcntl isn't working */
  protected $errorQueue = [];
  protected $queue = [];

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
    // Create a temporary file for the forked thread to write serialized
    // output back to the parent process.
    $channel = new Channel();

    // Create the fork or run the callable $func in the same thread using the queue.
    $pid = function_exists('pcntl_fork') ? pcntl_fork() : static::FORK_ERROR;
    if ($pid == static::FORK_ERROR) {
      $this->errorQueue[] = $func;
      return false;
    }

    // If this is the parent thread, we'll have the child pid value.
    // Store the pid information in the parent thread. This conditional
    // will return false for child threads.
    if ($pid !== static::FORKED_THREAD) {
      $this->threads[$pid] = [$channel, $func];
      return $pid;
    }
    // Only the forked thread will run this.
    // Using the channel returns the output to the parent.
    $channel->send($func());
    // Job of the fork completed. We can exit now.
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
    while (!empty($this->threads) || !empty($this->queue)) {
        $this->workQueue();

        reset($this->threads);

        // Unload a thread pid.
        while ($pid = key($this->threads)) {

          $channel = $this->threads[$pid][0];
          if (!$channel->isOpen()) {
            unset($this->threads[$pid]);
            next($this->threads);
          }

          foreach ($channel->read() as $response) {
              yield $response;
          }

          pcntl_waitpid($pid, $status, WNOHANG);

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
}
