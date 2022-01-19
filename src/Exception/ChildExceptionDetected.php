<?php

namespace Async\Exception;

class ChildExceptionDetected implements \Serializable {
  public $message;
  public $code;
  public $trace;
  public $class;
  public $line;
  public $file;


  public function __construct(\Exception $e) {
    $this->message = $e->getMessage();
    $this->code = $e->getCode();
    $this->trace = $e->getTraceAsString();
    $this->class = get_class($e);
    $this->line = $e->getLine();
    $this->file = $e->getFile();
  }

  public function __toString(): string {
    return strtr("[code]: class: message on Line line in file \ntrace", (array) $this);
  }

  public function serialize() {
      return serialize([
        $this->message,
        $this->code,
        $this->trace,
        $this->class,
        $this->line,
        $this->file,
      ]);
  }
  public function unserialize($data) {
      list($this->message, $this->code, $this->trace, $this->class, $this->line, $this->file) = unserialize($data);
      throw new ForkException((string) $this);
  }
}
