<?php

namespace Async\Exception;

class InactiveForkException extends ForkException {
  protected int $forkCode = 173;
}
