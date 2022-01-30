<?php

namespace Async;

use Async\Event\ForkEvent;
use Async\Exception\InactiveForkException;
use Async\Exception\ForkWaitTimeoutException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Psr\Log\LoggerInterface;

function_exists('pcntl_async_signals') && pcntl_async_signals(TRUE);

class ForkManager {
    /** @var int wait time in microseconds **/
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

    /** @var float */
    protected float $waitBackoff = 1.0;

    /** @var float */
    protected float $waitTimeout = 36000;

    protected int $payloadsAck = 0;

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
     * Set the wait timeout for messages to arrive from forks.
     */
    public function setWaitTimeout($number):ForkManager
    {
      $this->waitTimeout = (float) $number;
      return $this;
    }

    /**
     * Run or queue a function to run asynchronously.
     */
    public function run(callable $func):Fork
    {
        $promise = new Fork($func, $this->channel, $this->logger);
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
     * Retrieve messages as they arrive in an iterable fashion.
     */
    public function receive():\Generator
    {
       // Used to enforce a timeout.
       $last_message_sent = time();
        do {
            if ($this->waitTimeout < (time() - $last_message_sent)) {
              $errmsg = sprintf('Timeout of %s seconds reached waiting for message from channel "%s".', $this->waitTimeout, $this->channel->getName());
              $this->logger->error($errmsg);
              $this->terminateForks();
              throw new ForkWaitTimeoutException($errmsg);
              return;
            }
            $new_messages = $this->listener->readMessages();
            $last_message_count = 0;

            // Listen for any return outcomes from other forks.
            foreach ($new_messages as $message) {
                $this->logger->debug(sprintf('Got message %s from %s.', $message->id(), $message->pid()));

                if (!isset($this->activeForks[$message->pid()])) {
                  throw new InactiveForkException(sprintf("Recieved message (%s) from inactive fork (%s)", $message->id(), $message->pid()));
                }
                else {
                  unset($this->activeForks[$message->pid()]);
                }

                $this->logger->debug(sprintf('Still %s forks remaining', count($this->activeForks)));
                $this->processQueue();
                yield $message->payload();

                // Reset timeout.
                $last_message_sent = time();
                $last_message_count++;
            }

            // Increase the backoff of the last message read had no messages.
            if ($last_message_count == 0) {
              $this->waitBackoff += 0.1;
              $this->logger->debug(sprintf('No new messages in channel "%s". Backoff: %f', $this->channel->getName(), $this->waitBackoff));
            }
            // Reset the wait backoff.
            else {
              $this->waitBackoff = 1.0;
            }

            $waitTime = self::WAIT_TIME * $this->waitBackoff;
            usleep($waitTime);
        }
        // Continue to recieve messages until there are no more active forks or
        // a timeout limit has been reached.
        while (count($this->activeForks));
    }

    /**
     * Exec a callable on each payload received.
     *
     * @return int The accumulative payloads received.
     */
    public function onReceive(callable $func):int
    {
      foreach ($this->receive() as $response) {
        $this->payloadsAck++;
        $func($response);
      }
      return $this->payloadsAck;
    }

    /**
     * Get the accumulative payloads received.
     */
    public function getPayloadCount():int
    {
      return $this->payloadsAck;
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
