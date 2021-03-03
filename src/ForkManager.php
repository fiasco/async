<?php

namespace Async;

use Async\Event\ForkEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Psr\Log\LoggerInterface;

function_exists('pcntl_async_signals') && pcntl_async_signals(TRUE);

class ForkManager {
    /** @var int wait time in microseconds **/
    protected const WAIT_TIME = 300000;

    /** @var int wait timeout in seconds **/
    protected const WAIT_TIMEOUT = 36000;

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

    protected float $waitBackoff = 1.0;

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
       $last_message_count = 0;
       $last_message_sent = time();
        do {
            if (self::WAIT_TIMEOUT < (time() - $last_message_sent)) {
              $this->logger->error(sprintf('Timeout reached waiting for message from channel "%s".', $this->channel->getName()));
              $this->terminateForks();
              return;
            }
            $new_messages = $this->listener->readMessages();

            // Listen for any return outcomes from other forks.
            foreach ($new_messages as $message) {
                $this->logger->debug(sprintf('Got message %s from %s.', $message->id(), $message->pid()));
                $fork = $this->activeForks[$message->pid()];
                $fork->process($message->payload(), $this->dispatcher);
                unset($this->activeForks[$message->pid()]);
                $this->logger->debug(sprintf('Still %s forks remaining', count($this->activeForks)));
                $this->processQueue();
                yield $message->payload();
                $last_message_sent = time();
            }

            if (empty($new_messages)) {
              $this->logger->debug(sprintf('No new messages in channel "%s". Backoff: %f', $this->channel->getName(), $this->waitBackoff));

              // Increase the backoff of the last message read had no messages.
              if ($last_message_count == 0) {
                $this->waitBackoff += 0.1;
              }
            }
            else {
              // Reset the wait backoff.
              $this->waitBackoff = 1.0;
            }
            $last_message_count = count($new_messages);

            $waitTime = self::WAIT_TIME * $this->waitBackoff;
            usleep($waitTime);
        }
        // Continue to recieve messages until there are no more active forks or
        // a timeout limit has been reached.
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
