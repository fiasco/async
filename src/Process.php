<?php

namespace Async;

use Async\Socket\Server;
use Async\Socket\Client;
use Async\Exception\ProcessException;
use Async\Exception\ChildExceptionDetected;

class Process {
  const STATUS_IS_PARENT = 0;
  const STATUS_IS_CHILD = 1;
  const STATUS_WAITINGFORCHILD = 3;
  const STATUS_CHILDCOMPLETE = 5;

  protected int $myPid;
  protected int $parentPid = 0;
  protected bool $disabled = false;
  protected Server $server;
  protected Client $client;
  protected array $forks = [];
  protected int $status = 1;

  public function __construct(?int $parent_pid = null, ?Server $server = null)
  {
    $this->myPid = getmypid();
    if ($parent_pid && !$server) {
      throw new SocketClientException("No communication client was provided.");
    }
    if ($server) {
      $this->server = $server;
    }
    if ($parent_pid) {
      $this->parentPid = $parent_pid;
      $pid = pcntl_fork();
      // Child thread gets a zero, the other thread is the parent.
      $this->status = ($pid == 0) ? self::STATUS_IS_CHILD : self::STATUS_IS_PARENT;
    }

    // The child needs a server client to send responses on.
    if ($this->status == self::STATUS_IS_CHILD && $server) {
      $this->client = $server->getClient();
    }

    // Child process doesn't know its pid yet so lets set it here.
    if ($this->status == self::STATUS_IS_CHILD) {
      $this->myPid = getmypid();
    }
    // This means we forked and this is the parent thread so we don't want it
    // to run any code.
    else {
      $this->disabled = true;
      $this->myPid = $pid;
    }
  }

  public function isDisabled():bool
  {
    return $this->disabled;
  }

  public function getPid()
  {
    return $this->myPid;
  }

  /**
   * This is used to track the status of a fork inside its parent thread.
   */
  public function setStatus(int $status):Process
  {
    switch ($status) {
      case self::STATUS_WAITINGFORCHILD:
      case self::STATUS_CHILDCOMPLETE:
        $this->status = $status;
        break;
      default:
        throw new ProcessException("Invalid status provided.");
    }
    return $this;
  }

  public function getStatus():int
  {
    return $this->status;
  }

  public function fork():Process
  {
    // Parent hosts the server to recieve communication from the child.
    if (!isset($this->server)) {
      $this->server = new Server();
    }
    $fork = new static(getmypid(), $this->server);
    // The fork event will split in to two threads and this object
    // Should be the opposite of the fork. If this is the parent thread,
    // it should NOT be disabled. It its th fork thread, it SHOULD be
    // disabled. So whatever the fork is, be the opposite.
    $this->disabled = !$fork->isDisabled();

    // Track fork in the parent thread.
    if (!$this->disabled) {
      $fork->setStatus(self::STATUS_WAITINGFORCHILD);
      $this->forks[$fork->getPid()] = $fork;
    }
    return $fork;
  }

  public function run(callable $callback)
  {
    if ($this->disabled) {
      return;
    }
    if ($this->client) {
      set_exception_handler(function ($e) {
        $this->client->send(new ChildExceptionDetected($e));
      });

      $this->client->send(call_user_func($callback, $this));
      exit;
    }
    // This is a parent thread run.
    return call_user_func($callback, $this);
  }

  public function receive():\Generator
  {
    if (!$this->server) {
      throw new ProcessException("Cannot recieve fork output: no server.");
    }
    while (true) {
      $waiting = array_filter($this->forks, fn($f) => $f->getStatus() == self::STATUS_WAITINGFORCHILD);
      if (!count($waiting)) {
        break;
      }
      $message = $this->server->receive();

      if (!isset($this->forks[$message->pid()])) {
        throw new ProcessException("Messge recieved for a fork that does not exist.");
      }

      $fork = $this->forks[$message->pid()];

      if ($fork->getStatus() != self::STATUS_WAITINGFORCHILD) {
        throw new ProcessException(sprintf("Recieved message from pid %d but wasn't expecting message.", $message->pid()));
      }

      // Completed now we've recieved a message from the fork.
      $fork->setStatus(self::STATUS_CHILDCOMPLETE);

      yield $message->payload();
    }
  }
}
