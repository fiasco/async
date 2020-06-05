<?php

namespace Async;

trait LoggerTrait
{
    public function log($message)
    {
        if (!$this->logger) {
          return;
        }
        // $message = sprintf('%s (%s): %s', $this->isParent() ? 'parent': 'child_fork', $this->pid, $message);
        $this->logger->debug($message);
    }
}


 ?>
