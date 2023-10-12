<?php

namespace Async\React;

use Async\MessageException;
use React\Socket\Connector;
use React\Socket\ConnectionInterface;
use React\EventLoop\Loop;
use Psr\Log\LoggerInterface;


class Client
{
    protected static LoggerInterface $logger;

    public static function setLogger(LoggerInterface $logger)
    {
        self::$logger = $logger;
    }

    /**
     * Send a message to the server.
     */
    public static function send(Message $message): Message
    {
        $connector = new Connector();

        $logger = isset(self::$logger) ? self::$logger : null;

        $partial = new PartialMessage();

        $connector->connect('127.0.0.1:8020')
          ->then(function (ConnectionInterface $connection) use ($partial, $logger, $message) {
              $connection->on('data', function ($data) use ($partial) {
                  $partial->addChunk($data);
              });
              $connection->write((string) $message);
          }, function (\Exception $e) use ($logger) {
              isset($logger) && $logger->error($e->getMessage());
          });

        // Block till message data has been sent and received.
        Loop::run();

        $response = $partial->getMessage();
        $level = $response->getMethod() == 'MISS' ? 'debug' : 'info';
        isset($logger) && $logger->log($level, sprintf("%s(%d): %s %s %s %d %d", __CLASS__, getmypid(), $message->getMethod(), $response->getMethod(), $response->getPath(), strlen($partial->getBuffered()), $partial->getChunks()));

        return $response;
    }

    /**
     * Put a message payload in the server.
     */
    public static function put($path, $payload): Message
    {
        return self::send(Message::create('PUT', $path, $payload));
    }

    /**
     * Get a message payload from the server.
     */
    public static function get($path): Message
    {
        return self::send(Message::create('GET', $path));
    }

    /**
     * Open registered lease with the server.
     */
    public static function register(): Message
    {
        return self::send(Message::create('REGISTER', getmypid()));
    }

    /**
     * Close registered lease with the server.
     */
    public static function close(): Message
    {
        try {
            return self::send(Message::create('EXIT', getmypid()));
        }
        catch (MessageException $e) {
            // This can happen if we are closing due to interuption.
        }   
    }

    /**
     * Get information from the server about current leases and stored information.
     */
    public static function getServerStatus(): Message
    {
        return self::send(Message::create('STATUS', getmypid()));
    }
}
