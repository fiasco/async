<?php

namespace Async\React;

use Async\MessageException;

class PartialMessage {
  protected string $buffer = '';
  protected int $chunks = 0;
  protected Message $message;

  public function addChunk(string $chunk)
  {
    $this->chunks++;
    $this->buffer .= $chunk;

    try {
      $this->message = Message::fromPayload($this->buffer);
    }
    catch (MessageException $e) {}
    return $this;
  }

  public function getChunks():int
  {
    return $this->chunks;
  }

  public function getBuffered():string
  {
    return $this->buffer;
  }

  public function isReady()
  {
    return isset($this->message);
  }

  public function getMessage():Message
  {
    return $this->message;
  }
}
