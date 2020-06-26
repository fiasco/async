<?php

namespace Async;

class ChannelListener {

    protected $fd;

    public function __construct($filename)
    {
        $this->fd = fopen($filename, 'r');
    }

    public function readMessages():array
    {
        $payload = '';
        while ($block = fread($this->fd, 1024)) {
          $payload .= $block;
        }
        $messages = [];
        foreach (explode(Message::PAYLOAD_END, $payload) as $data_bundle) {
            $messages[] = Message::fromPayload($data_bundle);
        }
        return array_filter($messages);
    }

    public function __destruct()
    {
      fclose($this->fd);
    }
}
