<?php

namespace Async;
use Async\Exception\ForkException;
use Async\Event\ForkEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Psr\Log\LoggerInterface;

class Fork {
    public const FORK_ERROR = -1;
    protected string $name;
    protected $callable;
    protected $channel;
    protected bool $isFork;
    protected bool $isParent;
    protected bool $completed = false;
    protected $onCompleteCallback = false;
    protected int $forkPid = -1;
    protected $payload;
    protected LoggerInterface $logger;

    public function __construct(callable $callable, Channel $channel, LoggerInterface $logger)
    {
        $this->callable = $callable;
        $this->channel = $channel;
        $this->parentPid = getmypid();
        $this->logger = $logger;
        $this->name = '';
    }

    public function setName(string $name):Fork
    {
      $this->name = $name;
      return $this;
    }

    public function run(EventDispatcher $dispatcher = null)
    {
      // Create the fork or run the callable $func in the same thread using the queue.
      // $this->log(sprintf('There are %s threads running. About to fork another...', count($this->threads)));
      $pid = function_exists('pcntl_fork') ? pcntl_fork() : static::FORK_ERROR;

      if ($pid === static::FORK_ERROR) {
          throw new ForkException("Fork attempted failed.");
      }

      $mypid = getmypid();

      $this->isFork = ($pid == 0);
      $this->isParent = ($this->parentPid == $mypid);
      $this->forkPid = $this->isFork ? $mypid : $pid;

      if ($dispatcher) {
          $event = new ForkEvent($this);
          $dispatcher->dispatch($event, ForkEvent::EVENT_FORK);
      }

      // If this is the parent thread, return the fork object.
      if ($this->isParent) {
        return $this;
      }

      $timer = microtime(true);

      $this->channel
        ->getPublisher()
        ->publish(new Message(call_user_func($this->callable)));

      $duration = microtime(true) - $timer;
      $this->logger->debug("Fork {$this->name} ({$this->forkPid}) completed in $duration seconds.");
      exit;
    }

    public function getForkPid():int
    {
        return $this->forkPid;
    }

    public function isFork():int
    {
        return $this->isFork;
    }

    public function process($payload, EventDispatcher $dispatcher = null):Fork
    {
        $this->payload = $payload;
        $this->completed = true;

        if ($dispatcher) {
            $event = new ForkEvent($this);
            $dispatcher->dispatch($event, ForkEvent::EVENT_FORK_COMPLETE);
        }

        if ($this->onCompleteCallback) {
            call_user_func($this->onCompleteCallback, $payload);
        }
        return $this;
    }

    public function onComplete(callable $callable)
    {
        $this->onCompleteCallback = $callable;
    }

    public function isCompleted():bool
    {
        return $this->completed;
    }

    public function getPayload()
    {
        return $this->payload;
    }
}
