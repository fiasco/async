<?php

namespace Async;

use Async\Event\ForkEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Psr\Log\LoggerInterface;

function_exists('pcntl_async_signals') && pcntl_async_signals(TRUE);

class ForkManager {
    protected const WAIT_TIME = 300000;

    /** @var Symfony\Component\EventDispatcher\EventDispatcher */
    protected $dispatcher;

    /** @var Psr\Log\LoggerInterface */
    protected $logger;

    /** @var array */
    protected array $activeForks = [];

    /** @var int */
    protected int $maxForks = 7;

    /** @var array */
    protected array $queue = [];

    /** @var Async\Channel */
    protected $channel;

    /** @var Async\ChannelListener */
    protected $listener;

    public function __construct(EventDispatcher $dispatcher = null, LoggerInterface $logger = null)
    {
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
        $this->channel = new Channel();
        $this->listener = $this->channel->getListener();

        Signal::register([Signal::SIGINT], function () {
            $this->terminateForks();
        });
    }

    /**
     * Run or queue a function to run asynchronously.
     */
    public function run(callable $func):Fork
    {
        $promise = new Fork($func, $this->channel);
        $this->queue[] = $promise;
        $this->processQueue();
        return $promise;
    }

    /**
     * Provision forks from the queue.
     */
    protected function processQueue():ForkManager
    {
        while ((count($this->activeForks) < $this->maxForks) && !empty($this->queue)) {
            $fork = array_shift($this->queue);
            $fork->run($this->dispatcher);
            $this->activeForks[$fork->getForkPid()] = $fork;
        }
        return $this;
    }

    /**
     * Pull any results sent from forks.
     */
    protected function processForks():ForkManager
    {
      // Listen for any return outcomes from other forks.
      foreach ($this->listener->readMessages() as $message) {
          // $this->log(sprintf('Got message %s from %s', $message->id(), $message->pid()));
          $fork = $this->activeForks[$message->pid()];
          $fork->process($message->payload(), $this->dispatcher);

          unset($this->activeForks[$message->pid()]);
      }
      return $this;
    }

    /**
     * Wait for all fork threads to complete.
     */
    public function wait():void
    {
        do {
            $this->processQueue();
            $completed = array_filter(array_keys($this->activeForks), function ($pid) {
                $child_pid = pcntl_waitpid($pid, $status, WNOHANG);
                return $child_pid > 0;
            });
            if (count($completed) < count($this->activeForks)) {
                $this->processForks();
            }
            else {
                usleep(self::WAIT_TIME);
            }
        }
        while (count($this->activeForks));
    }

    /**
     * Retrieve messages as they arrive in an iterable fashion.
     */
    public function receive():\Generator
    {
        do {
            // Listen for any return outcomes from other forks.
            foreach ($this->listener->readMessages() as $message) {
                $this->logger->debug(sprintf('Got message %s from %s.', $message->id(), $message->pid()));
                $fork = $this->activeForks[$message->pid()];
                $fork->process($message->payload(), $this->dispatcher);
                unset($this->activeForks[$message->pid()]);
                $this->logger->debug(sprintf('Still %s forks remaining', count($this->activeForks)));
                $this->processQueue();
                yield $message->payload();
            }
            usleep(self::WAIT_TIME);
        }
        while (count($this->activeForks));
    }

    /**
     * Send SIGKILL posix kill command to active forks.
     */
    public function terminateForks():ForkManager
    {
        foreach ($this->activeForks as $pid => $fork) {
            // Don't allow forks to run this command.
            if ($fork->isFork()) {
              return $this;
            }
            posix_kill($pid, SIGKILL);
        }
        pcntl_signal_dispatch();

        return $this;
    }

    /**
     * Set the maximum number of forks allowed for a ForkManager instance.
     */
    public function setMaxForks(int $max):ForkManager
    {
        $this->maxForks = $max;
        return $this;
    }
}
