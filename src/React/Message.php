<?php

namespace Async\React;

use Async\MessageException;

class Message implements \Serializable {

    protected string $method;
    protected string $path;
    protected $payload;
    protected int $timestamp;

    /**
     * Create a new message to send down a channel.
     */
    public function __construct($path, string $method = 'GET', $payload = null)
    {
      $this->method = $method;
      $this->payload = $payload;
      $this->timestamp = $timestamp ?? time();
      $this->path = $path;
    }

    static public function create(string $method = 'GET', $path, $payload = null)
    {
      return new static($path, $method, $payload);
    }

    /**
     * Format into payload.
     */
    public function __toString():string
    {
      // base64 ensures any end of line (EOL) characters are not incorrectly
      // interpreted by the server as a premature transfer completion.
      $payload = base64_encode(serialize($this));
      // PHP_EOL is how the server knows the payload is complete.
      return $payload;
    }

    public static function fromPayload(string $message):Message
    {
        if (!$decoded = base64_decode($message)) {
          throw new MessageException("Could not decode payload.");
        }
        $data = @unserialize($decoded);

        // Detect when unserialization fails.
        if ($data === FALSE) {
          throw new MessageException("Could not unserialize payload.");
        }
        return $data;
    }

    public function getPayload()
    {
      return $this->payload;
    }

    public function getTimestamp()
    {
      return $this->timestamp;
    }

    public function getPath()
    {
      return $this->path;
    }

    public function getMethod()
    {
      return $this->method;
    }

    public function serialize()
    {
      return serialize([
        'path' => $this->path,
        'method' => $this->method,
        'payload' => $this->payload,
        'timestamp' => $this->timestamp
      ]);
    }

    public function unserialize($data)
    {
      $data = unserialize($data);
      $this->path = $data['path'];
      $this->method = $data['method'];
      $this->payload = $data['payload'];
      $this->timestamp = $data['timestamp'];
    }
}
