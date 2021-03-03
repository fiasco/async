<?php

namespace Async;

class Channel {

    protected $fd;
    protected $filename;


    public function __construct($name = null)
    {
        $this->filename = $name ?? tempnam('', 'async_channel');
        touch($this->filename);

        Signal::register([Signal::SIGINT], function () {
          $this->close();
        });
    }

    public function getName():string
    {
      return $this->filename;
    }

    public function getPublisher():ChannelPublisher
    {
        return new ChannelPublisher($this->filename);
    }

    public function getListener():ChannelListener
    {
        return new ChannelListener($this->filename);
    }

    public function close()
    {
        @unlink($this->filename);
    }
}
