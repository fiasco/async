<?php

namespace Async;

class Channel {
    protected const BLOCK_SIZE = 128;
    protected const PAYLOAD_END = "---END---\n";
    protected const BLOCK_END = "\r\n";

    protected $fd;
    protected $info;

    public function __construct()
    {
      // Create a temporary file for the forked thread to write serialized
      // output back to the parent process.
      $this->fd = tmpfile();
      $this->info = stream_get_meta_data($this->fd);
    }

    public function __destruct()
    {
        do {
          $stat = fstat($this->fd);
          if ($stat['size'] > 0) {
            usleep(100000);
          }
        }
        while ($stat['size'] > 0);
        $this->close();
    }

    public function close()
    {
      fclose($this->fd);
      file_exists($this->info['uri']) && unlink($this->info['uri']);
    }

    public function isOpen()
    {
      return file_exists($this->info['uri']);
    }

    public function send($data)
    {
        $this->lock(function () use ($data) {
          fseek($this->fd, 0, SEEK_END);
          fwrite($this->fd, $this->formatPayload($data));
          fwrite($this->fd, static::PAYLOAD_END);
        });
    }

    protected function lock(callable $func) {
      $attempts = 0;
      while ($attempts < 100) {
        if (!flock($this->fd, LOCK_EX)) {
          sleep(1);
          $attempts++;
          continue;
        }
        $return = $func();
        flock($this->fd, LOCK_UN);
        return $return;
      }
      throw new \RuntimeException("Could not obtain lock on file.");
    }

    public function listen()
    {
        while ($this->isOpen()) {
          $payloads = $this->lock(function () {
              return $this->readPayloads();
          });

          foreach ($payloads as $payload) {
            yield $payload;
          }
          usleep(100000);
        }
    }

    /**
     * Like listen() but doesn't look till the channel closes.
     */
    public function read()
    {
      return $this->lock(function () {
          return $this->readPayloads();
      });
    }

    protected function readPayloads()
    {
      // Set file descriptor to the begining of the file.
      rewind($this->fd);

      $payload = '';
      $payloads = [];
      $length = static::BLOCK_SIZE + strlen(static::BLOCK_END);

      // Read block lengths from the file.
      while ($block = fread($this->fd, $length)) {
        // If we encounter the end of a payload, then stop
        // aggregating the result.
        if (strpos($block, static::PAYLOAD_END) !== false) {
          $payload .= str_replace(static::BLOCK_END, '', $block);
          $payloads[] = $payload;
          $payload = '';
          continue;
        }
        $payload .= $block;
      }
      // If there is no payload yet. Then exist again and
      // check back again later.
      if (empty($payloads)) {
        return [];
      }

      // Now we've read the payload we can safely truncate the file.
      ftruncate($this->fd, 0);

      foreach ($payloads as &$payload) {
        $payload = $this->unloadPayload($payload);
      }

      return $payloads;
    }

    protected function formatPayload($payload)
    {
      $payload = base64_encode(serialize($payload));
      $lines = str_split($payload, static::BLOCK_SIZE);

      array_walk($lines, function (&$line) {
        $line = str_pad($line, static::BLOCK_SIZE) . static::BLOCK_END;
      });

      return implode('', $lines).static::PAYLOAD_END;
    }

    protected function unloadPayload($payload)
    {
      return unserialize(base64_decode(str_replace("\r\n", "", $payload)));
    }
}
