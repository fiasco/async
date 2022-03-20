<?php

namespace Async;

use Async\Socket\Server;
use Async\Socket\Client;
use Async\Exception\ProcessException;
use Async\Exception\ChildExceptionDetected;

class Process implements \Serializable {
  const STATUS_IS_PARENT = 0;
  const STATUS_IS_CHILD = 1;
  const STATUS_WAITINGFORCHILD = 3;
  const STATUS_CHILDCOMPLETE = 5;
  const STATUS_CHILDERROR = 7;

  protected int $myPid;
  protected int $parentPid = 0;
  protected bool $disabled = false;
  protected Server $server;
  protected Client $client;
  protected array $forks = [];
  protected int $status = 1;
  protected \Closure $onSuccessCallback;
  protected \Closure $onErrorCallback;
  protected $result;
  protected string $title;

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
    $this->title = sprintf("Process #%d", $this->myPid);
  }

  public function isDisabled():bool
  {
    return $this->disabled;
  }

  public function getPid():int
  {
    return $this->myPid;
  }

  public function setTitle(string $title):Process
  {
    $this->title = $title;
    return $this;
  }

  public function getTitle():string
  {
    return $this->title;
  }

  /**
   * This is used to track the status of a fork inside its parent thread.
   */
  public function setStatus(int $status):Process
  {
    switch ($status) {
      case self::STATUS_WAITINGFORCHILD:
      case self::STATUS_CHILDCOMPLETE:
      case self::STATUS_CHILDERROR:
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

  public function fork(?callable $callback = null):Process
  {
    $this->status = self::STATUS_IS_PARENT;
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

    if (isset($callback)) {
      return $fork->run($callback);
    }
    return $fork;
  }

  public function run(callable $callback)
  {
    if ($this->disabled) {
      return $this;
    }
    if ($this->client) {
      set_exception_handler(function ($e) {
        $this->setResult(new ChildExceptionDetected($e));
        $this->setStatus(self::STATUS_CHILDERROR);
        $this->client->send($this);
      });
      $this->setResult(call_user_func($callback, $this));
      $this->setStatus(self::STATUS_CHILDCOMPLETE);
      $this->client->send($this);
      exit;
    }
    // This is a parent thread run.
    return call_user_func($callback, $this);
  }

  public function onSuccess(\Closure $callback):Process
  {
    $this->onSuccessCallback = $callback;
    return $this;
  }

  public function onError(\Closure $callback):Process
  {
    $this->onErrorCallback = $callback;
    return $this;
  }

  public function setResult($result):Process
  {
    $this->result = $result;
    if ($this->status == self::STATUS_CHILDERROR && isset($this->onErrorCallback)) {
      $callback = $this->onErrorCallback;
    }
    if ($this->status == self::STATUS_CHILDCOMPLETE && isset($this->onSuccessCallback)) {
      $callback = $this->onSuccessCallback;
    }
    if (isset($callback)) {
      $callback($result, $this);
    }
    return $this;
  }

  public function getResult()
  {
    return $this->result;
  }

  public function awaitForks():Process
  {
    if ($this->getStatus() != self::STATUS_IS_PARENT) {
      throw new ProcessException("Cannot await forks on child process. Use parent process.");
    }
    if (!$this->server) {
      throw new ProcessException("Cannot recieve fork output: no server.");
    }
    while (true) {
      $waiting = array_filter($this->forks, fn($f) => $f->getStatus() == self::STATUS_WAITINGFORCHILD);
      if (!count($waiting)) {
        break;
      }

      $fork_c = $this->server->receive()->payload();

      if (!$fork_c instanceof Process) {
        throw new ProcessException("Recieved non-Process payload from async server.");
      }

      if (!isset($this->forks[$fork_c->getPid()])) {
        throw new ProcessException("Messge recieved for a fork that does not exist.");
      }

      $fork_s = $this->forks[$fork_c->getPid()];

      // Set the status and result from the client to the fork representation
      // here in the parent thread.
      $fork_s->setTitle($fork_c->getTitle())
             ->setStatus($fork_c->getStatus())
             ->setResult($fork_c->getResult());
    }
    return $this;
  }

  public function getForkResults():array
  {
    $r = array_filter($this->forks, fn($f) => $f->getStatus() == self::STATUS_CHILDCOMPLETE);
    return array_map(fn($f) => $f->getResult(), $r);
  }

  /**
   * {@inheritdoc}
   */
  public function serialize()
  {
    return serialize([
      'myPid' => $this->myPid,
      'result' => $this->result ?? null,
      'title' => $this->title,
      'status' => $this->status,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function unserialize($data)
  {
    $attributes = unserialize($data);
    $this->myPid  = $attributes['myPid'];
    $this->result = $attributes['result'];
    $this->title = $attributes['title'];
    $this->status = $attributes['status'];
  }
}
