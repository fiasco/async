<?php

namespace Async;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

class ChannelLogger implements LoggerInterface {
    use LoggerTrait;

    protected $channel;

    public function __construct(Channel $channel) {
      $this->channel = $channel;
    }

    /**
     * {@inheritdoc}
     */
    public function log($level, $message, array $context = array())
    {
        $data = [
          'level' => $level,
          'message' => $message
        ];

        if (isset($context['exception']) && ($context['exception'] instanceof \Exception)) {
          $data['exception_message'] = $context['exception']->getMessage();
          $data['exception_trace'] = $context['exception']->getTraceAsString();
        }

        $this->channel->send($data);
    }
}
