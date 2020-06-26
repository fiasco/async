<?php

namespace Async;

class ChannelPublisher {

    protected $filename;

    public function __construct($filename)
    {
        $this->filename = $filename;
    }

    public function publish(Message $message)
    {
        $fd = fopen($this->filename, 'a');
        $return = fwrite($fd, (string) $message);
        fclose($fd);
        return $return;
    }
}
