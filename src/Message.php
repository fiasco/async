<?php

namespace Async;

class Message {

    const SERIALIZED_FALSE = 'b:0;';
    const PAYLOAD_START = '<MSG id="%s">';
    const PAYLOAD_END = "</MSG>\r\n";

    protected $payload;
    protected $id;
    protected $pid;
    protected static $counter = 0;

    /**
     * Create a new message to send down a channel.
     */
    public function __construct($payload, int $pid = null, int $id = null)
    {
      $this->payload = $payload;
      if (!isset($id)) {
        self::$counter++;
      }
      $this->pid = $pid ?? getmypid();
      $this->id = $id ?? self::$counter;
    }

    /**
     * Format into payload.
     */
    public function __toString():string
    {
      $payload = base64_encode(serialize($this->payload));
      $start = sprintf(static::PAYLOAD_START, $this->pid.':'.$this->id);
      return $start.$payload.static::PAYLOAD_END;
    }

    public static function fromPayload(string $message)
    {
        // Extract the message ID.
        preg_match('/<MSG id="(\d+)\:(\d+)">/', $message, $matches);
        if (empty($matches)) {
          return false;
        }
        list($tag,$pid, $id) = $matches;
        $payload = strtr($message, [
          $tag => '',
          static::PAYLOAD_END => '',
        ]);
        if (!$decoded = base64_decode($payload)) {
          throw new MessageException("Could not decode payload: $payload.");
        }

        $data = unserialize($decoded);

        // Detect when unserialization fails.
        if ($data === FALSE && $decoded != static::SERIALIZED_FALSE) {
          throw new MessageException("Could not unserialize payload: $decoded.");
        }
        return new static($data, $pid, $id);
    }

    public function id()
    {
      return $this->id;
    }

    public function pid()
    {
      return $this->pid;
    }

    public function payload()
    {
      return $this->payload;
    }
}
