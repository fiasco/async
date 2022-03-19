<?php

namespace Async;

use Async\Socket\Server;

function_exists('pcntl_async_signals') && pcntl_async_signals(TRUE);

class ForkManager {
  protected Server $server;
  protected Process $process;
  protected array $queue = [];
  protected int $maxForks = 7;
  protected array $activeForks = [];

  public function __construct()
  {
    $this->server = new Server();
    $this->process = new Process();
  }

  public function run(callable $callback)
  {
    $this->queue[] = $callback;
    $this->processQueue();
  }

  public function recieve(): \Generator
  {
    while (count($this->activeForks)) {
      // Send marko/polo.
      $this->process->fork()->run(function () {
        sleep(10);
        $this->server->getClient()->send('MARKO');
        exit;
      });

      $payload = $this->server->recieve();
      if ($payload != 'MARKO') {
        array_shift($this->activeForks);
        yield $payload;
      }
    }
  }

  protected function processQueue()
  {
    while ((count($this->activeForks) < $this->maxForks) && ($callback = array_shift($this->queue))) {
      $fork = $this->process->fork();
      $this->process->run(function () use ($fork) {
        $this->activeForks[] = $fork->getPid();
      });

      $fork->run(function () use ($callback) {
        $this->server->getClient()->send($callback());
        exit;
      });
    }
  }
}
