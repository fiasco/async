<?php

namespace Async;

class Message {

    protected $payload;
    protected int $pid;
    protected int $timestamp;

    /**
     * Create a new message to send down a channel.
     */
    public function __construct($payload, int $pid = null, int $timestamp = null)
    {
      $this->payload = $payload;
      $this->pid = $pid ?? getmypid();
      $this->timestamp = $timestamp ?? time();
    }

    /**
     * Format into payload.
     */
    public function __toString():string
    {
      // base64 ensures any end of line (EOL) characters are not incorrectly
      // interpreted by the server as a premature transfer completion.
      $payload = base64_encode(serialize([
        'payload' => $this->payload,
        'pid' => $this->pid,
        'timestamp' => $this->timestamp
      ]));
      // PHP_EOL is how the server knows the payload is complete.
      return $payload.PHP_EOL;
    }

    public static function fromPayload(string $message)
    {
        if (!$decoded = base64_decode($message)) {
          throw new MessageException("Could not decode payload: $message.");
        }
        $data = unserialize($decoded);

        // Detect when unserialization fails.
        if ($data === FALSE) {
          throw new MessageException("Could not unserialize payload: $decoded.");
        }
        return new static($data['payload'], $data['pid'], $data['timestamp']);
    }

    public function pid()
    {
      return $this->pid;
    }

    public function payload()
    {
      return $this->payload;
    }

    public function timestamp()
    {
      return $this->timestamp();
    }
}
