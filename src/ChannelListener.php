<?php

namespace Async;

class ChannelListener {

    protected $fd;
    protected $filename;

    public function __construct($filename)
    {
        $this->filename = $filename;
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
            try {
                $messages[] = Message::fromPayload($data_bundle);
            }
            catch (MessageException $e) {
                echo "\n\nERROR[{$this->filename}]: ".$e->getMessage().PHP_EOL;
            }
        }
        return array_filter($messages);
    }

    public function __destruct()
    {
      fclose($this->fd);
    }
}
