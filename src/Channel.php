<?php

namespace Async;

use Psr\Log\LoggerInterface;

class Channel {
    use LoggerTrait;

    protected const BLOCK_SIZE = 128;
    protected const PAYLOAD_START = "---MSG";
    protected const PAYLOAD_END = "---END---";
    protected const BLOCK_END = "\r\n";
    protected const MODE_READ_ONLY = 0;
    protected const MODE_WRITE = 1;


    protected $fd;
    protected $locked = false;
    protected $payloadCounter = 0;
    protected $logger;
    protected $mode;
    protected $filename;

    /**
     * Create a temp file to communicate data to between parent and child procs.
     */
    public function __construct(LoggerInterface $logger = null)
    {
      // Create a temporary file for the forked thread to write serialized
      // output back to the parent process.
      $this->filename = tempnam('', 'async_channel');
      $this->fd = fopen($this->filename, 'a+');
      $this->mode = static::MODE_WRITE;
      $this->logger = $logger;
    }

    public function waitForClearChannel()
    {
      do {
        if (!$fd = fopen($this->filename, 'r')) {
          $this->log("Shutting down (".getmypid().") and found the channel already broken.");
          break;
        }
        $stat = fstat($fd);
        if ($stat['size'] > 0) {
          fclose($fd);
          clearstatcache();
          usleep(600000);
          $this->log("Waiting to close but there is {$stat['size']} bytes of data in the channel: {$this->filename}.");
        }
      }
      while ($stat['size'] > 0);
      return $this;
    }

    /**
     * Close the communication connection between parent and child fork.
     */
    public function close()
    {
      fclose($this->fd);
      $this->log("Unlinking {$this->filename}.");
      file_exists($this->filename) && unlink($this->filename);
    }

    /**
     * Determine if the bridge is intact still.
     */
    public function isOpen()
    {
      return !is_bool($this->fd);
    }

    /**
     * Send data to the channel.
     *
     * Only unidirectional data transmission is supported.
     * Please use two channels to achive bi-directional flow.
     */
    public function send($data)
    {
        if ($this->mode === static::MODE_READ_ONLY) {
          throw new \Exception("Cannot write to channel in read-only mode.");
        }
        $this->lock(function () use ($data) {
          $this->log("writting data..");
          fseek($this->fd, 0, SEEK_END);
          fwrite($this->fd, $this->formatPayload($data));
          fwrite($this->fd, static::BLOCK_END);
        });
    }

    /**
     * Obtain a lock on the temporary file to ensure clean transmission.
     *
     * @param $func callable The callback to run when the lock is obtained.
     */
    protected function lock(callable $func) {
      $attempts = 0;

      // Only attempt the lock a maximum of 100 times.
      while ($attempts < 100) {

        // If an exclusive lock (LOCK_EX) cannot be obtained, sleep
        // and try again.
        if (!flock($this->fd, LOCK_EX)) {
          usleep(200000); // 200ms
          $attempts++;
          continue;
        }
        $this->locked = true;
        $return = $func();
        flock($this->fd, LOCK_UN);
        $this->locked = false;
        return $return;
      }
      throw new \RuntimeException("Could not obtain lock on file.");
    }

    /**
     * Listen for payloads on the channel.
     *
     * This function creates a loop to read all the data coming
     * back from the channel until the channel is closed.
     */
    public function listen():\Generator
    {
        while ($this->isOpen()) {
          $payloads = $this->read();

          foreach ($payloads as $msgid => $payload) {
            yield $payload;
          }
          // Pause if no payloads were obtained.
          empty($payloads) && usleep(100000);
        }
    }

    /**
     * Attempt to read payloads off the channel.
     */
    public function read():iterable
    {
      return $this->lock(function () {
          return $this->readPayloads();
      });
    }

    /**
     * Attempt to read payloads off the channel.
     */
    protected function readPayloads()
    {
      if (!$this->locked) {
        throw new \RuntimeException(__METHOD__ . ' requires a channel lock in order to run.');
      }
      rewind($this->fd);

      $payloads = [];
      $length = static::BLOCK_SIZE + strlen(static::BLOCK_END);

      $payload = '';
      // Read block lengths from the file.
      while ($block = fgets($this->fd)) {
        $block = trim($block);
        if (strpos($block, static::PAYLOAD_START) !== false) {
            $tag = preg_quote(static::PAYLOAD_START);
            $msgid = preg_replace('/^'.$tag.'([0-9]+)\>0+([1-9][0-9]*)/', '$1-$2', $block);

            // At this point the block should be empty. If thats not the case,
            // the the channel is corrupt and a new message has begun in the
            // middle of an existing message.
            if (!empty($payload)) {
              trigger_error("Corrupt message detected in channel. A new message appears to have overwritten an existing message.");
            }
            continue;
        }
        // If we encounter the end of a payload, then stop
        // aggregating the result.
        if (strpos($block, static::PAYLOAD_END) !== false) {
          $payloads[$msgid] = $payload;
          $payload = '';
          continue;
        }

        // Append the block to the payload.
        $payload .= $block;
      }

      // If we have a left over payload.. this is bad.
      // It technically shouldn't happen since we have an exclusive
      // lock on the file.
      if (!empty($payload)) {
          trigger_error("Channel found a partial payload: '$payload'.");
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

    /**
     * Make the data safe for transit.
     */
    protected function formatPayload($payload)
    {
      $payload = base64_encode(serialize($payload));
      $lines = str_split($payload, static::BLOCK_SIZE);

      $this->payloadCounter++;

      // Create a unique message header.
      $start_line = static::PAYLOAD_START.getmypid().'>';
      $len = strlen($start_line);
      $start_line .= str_pad($this->payloadCounter, static::BLOCK_SIZE - $len, '0', STR_PAD_LEFT);

      array_unshift($lines, $start_line);

      array_walk($lines, function (&$line) {
        $line = str_pad($line, static::BLOCK_SIZE) . static::BLOCK_END;
      });

      return implode('', $lines).static::PAYLOAD_END;
    }

    /**
     * Convert payload back into php data.
     */
    protected function unloadPayload($payload)
    {
      return unserialize(base64_decode(str_replace("\r\n", "", $payload)));
    }
}
