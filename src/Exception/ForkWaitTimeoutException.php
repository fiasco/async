<?php

namespace Async\Exception;

class ForkWaitTimeoutException extends ForkException {
  protected int $forkCode = 172;
}
