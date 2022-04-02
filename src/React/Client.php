<?php

namespace Async\React;

use React\Socket\Connector;
use React\Socket\ConnectionInterface;
use React\Stream\WritableResourceStream;
use React\EventLoop\Loop;
use Psr\Log\LoggerInterface;
use Async\MessageException;

class Client {

  static protected LoggerInterface $logger;

  public static function setLogger(LoggerInterface $logger)
  {
    self::$logger = $logger;
  }

  public static function send(Message $message):Message
  {
    $connector = new Connector();

    $logger = isset(self::$logger) ? self::$logger : null;

    $partial = new PartialMessage;

    $connector->connect('127.0.0.1:8020')
      ->then(function (ConnectionInterface $connection) use ($partial, $logger, $message) {
        $connection->on('data', function ($data) use ($partial) {
          $partial->addChunk($data);
        });
        $connection->write((string) $message);
    }, function (\Exception $e) use ($logger) {
        $logger->error($e->getMessage());
    });

    // Block till message data has been sent and received.
    Loop::run();
    $response = $partial->getMessage();

    if (isset($logger)) {
      $logger->info(sprintf("%s(%d): %s %s %s %d %d", __CLASS__, getmypid(), $message->getMethod(), $response->getMethod(), $response->getPath(), strlen($partial->getBuffered()), $partial->getChunks()));
    }

    return $response;
  }

  static public function put($path, $payload):Message
  {
    return self::send(Message::create('PUT', $path, $payload));
  }

  static public function get($path):Message
  {
    return self::send(Message::create('GET', $path));
  }

  static public function register():Message
  {
    return self::send(Message::create('REGISTER', getmypid()));
  }

  static public function close():Message
  {
    return self::send(Message::create('EXIT', getmypid()));
  }
}
