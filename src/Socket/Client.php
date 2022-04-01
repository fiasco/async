<?php

namespace Async\Socket;

use Async\Exception\SocketClientException;
use Async\Message;
use Psr\Log\LoggerAwareTrait;

class Client {

  const MAX_RETRIES = 600;

  use LoggerAwareTrait;

  protected $socket;

  public function __construct(string $address = '127.0.0.1', int $port = 8111)
  {
    $this->address = $address;
    $this->port = $port;
  }

  public function send($payload):bool
  {
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

    if ($socket === false) {
        throw new SocketClientException("socket_create() failed: reason: " . socket_strerror(socket_last_error()));
    }

    $retries = 0;
    while(!$result = @socket_connect($socket, $this->address, $this->port)) {
      if (isset($this->logger)) {
        $this->logger->info("Socket server not up yet: {error}. Sleeping...", [
          'error' => socket_strerror(socket_last_error($socket)),
        ]);
      }
      sleep(1);
      $retries++;

      if ($retries > self::MAX_RETRIES) {
        break;
      }
    }

    if ($result === false) {
        throw new SocketClientException("socket_connect() failed.\nReason: ($result) " . socket_strerror(socket_last_error($socket)));
    }

    $message = new Message($payload);
    $payload = (string) $message;
    socket_write($socket, $payload, strlen($payload));

    $response = socket_read($socket, 2048);
    socket_close($socket);
    return $response == Server::ACK;
  }
}
