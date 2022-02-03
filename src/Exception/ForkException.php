<?php

namespace Async\Exception;

class ForkException extends \Exception {
  protected int $forkCode = 171;

  public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
  {
    parent::__construct($message, $this->forkCode, $previous);
  }
}
