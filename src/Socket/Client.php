<?php

namespace Async\Socket;

use Async\Exception\SocketClientException;
use Async\Message;

class Client {

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

    $result = socket_connect($socket, $this->address, $this->port);

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
