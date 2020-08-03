<?php

namespace Async\Event;

use Async\Fork;
use Symfony\Contracts\EventDispatcher\Event;

class ForkEvent extends Event {

    public const EVENT_FORK = 'async.fork';
    public const EVENT_FORK_COMPLETE = 'async.fork.complete';

    protected $fork;

    public function __construct(Fork $fork)
    {
        $this->fork = $fork;
    }

    public function isFork()
    {
        return $this->fork->isFork();
    }

    public function isParent()
    {
        return $this->fork->isParent();
    }

    public function getFork()
    {
        return $this->fork;
    }

    public function getPayload()
    {
        return $this->fork->getPayload();
    }
}
