<?php

namespace Async;

class ChannelListener {

    protected $fd;
    protected $filename;
    protected $pos;
    protected array $messageReceipts = [];

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
                if (!$message = Message::fromPayload($data_bundle)) {
                  continue;
                }

                // Ensure disk cache doesn't read in the same message twice.
                $receipt = $message->id() . ':' . $message->pid();
                if (!in_array($receipt, $this->messageReceipts)) {
                  $messages[] = $message;
                  $this->messageReceipts[] = $receipt;
                }
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
