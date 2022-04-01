<?php

namespace Async\Socket;

use Async\Exception\SocketServerException;
use Async\Message;
use Async\Process;
use Psr\Log\LoggerAwareTrait;

class Server {

  use LoggerAwareTrait;

  const ACK = 'ACK';
  const MARKO = 'MARKO';
  /* 32MB */
  const READ_MAX_LENGTH = 33554432;

  protected $socket;
  protected $msgSocket = false;
  protected string $address;
  protected int $port;
  protected int $waitTimeout;
  protected int $lastMessageTimestamp;

  public function __construct(string $address = '127.0.0.1', int $port = 8111, int $max_conns = 10, int $waitTimeout = 600)
  {
    ob_implicit_flush();

    if (($sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) {
      throw new SocketServerException("socket_create() failed: reason: " . socket_strerror(socket_last_error()));
    }

    while (@socket_bind($sock, $address, $port) === false) {
      // throw new SocketServerException("socket_bind() failed: reason: " . socket_strerror(socket_last_error($sock)));
      $port++;
    }

    $this->waitTimeout = $waitTimeout;
    $this->address = $address;
    $this->port = $port;

    if (socket_listen($sock, $max_conns) === false) {
      throw new SocketServerException("socket_listen() failed: reason: " . socket_strerror(socket_last_error($sock)));
    }

    $this->socket = $sock;
  }

  public function getClient():Client
  {
    $client = new Client($this->address, $this->port);
    if (isset($this->logger)) {
      $client->setLogger($client);
    }
    return $client;
  }

  public function receive():Message
  {
    $this->msgSocket = $this->msgSocket ? $this->msgSocket : socket_accept($this->socket);
    if ($this->msgSocket === false) {
        throw new SocketServerException("socket_accept() failed: reason: " . socket_strerror(socket_last_error($this->socket)));
    }

    $buffer = socket_read($this->msgSocket, self::READ_MAX_LENGTH, PHP_NORMAL_READ);
    if (false === $buffer) {
        throw new SocketServerException("socket_read() failed: reason: " . socket_strerror(socket_last_error($this->msgSocket)));
    }

    socket_write($this->msgSocket, self::ACK, strlen(self::ACK));
    socket_close($this->msgSocket);
    $this->msgSocket = false;
    $message = Message::fromPayload($buffer);

    // If we recieve a timeout message but haven't timed out yet
    // recurse the call to wait again. Effectively, this means we're
    // ignoring the timeout MARKO and returning to reading the socket
    // for another response.
    if ($this->checkForTimeout($message)) {
      return $this->recieve();
    }
    $this->lastMessageTimestamp = time();
    return $message;
  }

  public function __destruct()
  {
    is_resource($this->msgSocket) && socket_close($this->msgSocket);
    is_resource($this->socket) && socket_close($this->socket);
  }

  /**
   * @throws SocketServerException when a timeout is reached.
   * @return bool True if no timeout is met. False if message was not a timeout check.
   */
  private function checkForTimeout(Message $message):bool
  {
    // Test for timeout condition.
    if ($message->payload() != self::MARKO) {
      return false;
    }
    if (empty($this->lastMessageTimestamp)) {
      throw new SocketServerException("Timeout of {$this->waitTimeout} reached waiting for a client response.");
    }

    $time_waited = $message->timestamp() - $this->lastMessageTimestamp;

    if ($time_waited >= $this->waitTimeout) {
      throw new SocketServerException("Timeout of {$this->waitTimeout} reached waiting for a client response.");
    }

    // This can occur when an old timeout is recieved long before the last
    // message was recieved.
    return true;
  }

}
