<?php

namespace Async;

class ChannelPublisher {

    protected $filename;

    public function __construct($filename)
    {
        $this->filename = $filename;
    }

    /**
     * Attempt to publish a message.
     */
    public function publish(Message $message, $retry = 0)
    {
        if ($retry >= 99) {
          throw new \RuntimeException("Could not acquire lock to send message to $this->filename.");
        }
        $fd = fopen($this->filename, 'a');
        if (!flock($fd, LOCK_EX)) {
          fclose($fd);
          sleep(1);
          return $this->publish($message, $retry++);
        }
        $return = fwrite($fd, (string) $message);
        flock($fd, LOCK_UN);
        fclose($fd);
        return $return;
    }
}
