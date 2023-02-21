<?php

namespace Async\React;

use React\Socket\SocketServer;
use React\Socket\ConnectionInterface;
use React\EventLoop\Loop;
use Evenement\EventEmitterTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Async\MessageException;

class Server
{
    use EventEmitterTrait;
    use LoggerTrait;

    protected $store = [];
    protected bool $halt = false;
    protected LoggerInterface $logger;
    protected array $buffer = [];
    protected array $leases = [];

    public function __construct(?LoggerInterface $logger = null)
    {
        if (isset($logger)) {
            $this->logger = $logger;
        }
        $this->on('PUT', function (Message $message, ConnectionInterface $connection) {
            $status = isset($this->store[$message->getPath()]) ? 'UPDATED' : 'NEW';
            $this->info('PUT '.$message->getPath().' '.$status.' '.$connection->getRemoteAddress());
            // $this->debug("Storing payload: ".serialize($message->getPayload()));
            $this->store[$message->getPath()] = $message->getPayload();
            $connection->end(Message::create($status, $message->getPath()));
        });

        $this->on('GET', function (Message $message, ConnectionInterface $connection) {
            $status = isset($this->store[$message->getPath()]) ? 'HIT' : 'MISS';
            $level = $status == 'MISS' ? 'debug' : 'info';
            $this->log($level, 'GET '.$message->getPath().' '.$status.' '.$connection->getRemoteAddress());
            $connection->end(Message::create($status, $message->getPath(), $this->store[$message->getPath()] ?? false));
        });

        $this->on('DELETE', function (Message $message, ConnectionInterface $connection) {
            $this->info('DELETE '.$connection->getRemoteAddress());
            unset($this->store[$message->getPath()]);
            $connection->end(Message::create('OK', $message->getPath()));
        });

        $this->on('EXIT', function (Message $message, ConnectionInterface $connection) {
            $this->info('EXIT '.$message->getPath().' BYE '.$connection->getRemoteAddress());
            unset($this->leases[$message->getPath()]);
            $connection->end(Message::create('BYE', $message->getPath()));
            $this->halt = count($this->leases) === 0;
        });

        $this->on('REGISTER', function (Message $message, ConnectionInterface $connection) {
            $this->info('REGISTER '.$message->getPath().' WELCOME '.$connection->getRemoteAddress());
            $this->leases[$message->getPath()] = time();
            $connection->end(Message::create('WELCOME', $message->getPath()));
        });

        $this->on('STATUS', function (Message $message, ConnectionInterface $connection) {
            $this->info('STATUS '.$message->getPath().' REQUESTED '.$connection->getRemoteAddress());
            $data = [
                'leases' => $this->leases,
                'store' => array_keys($this->store),
            ];
            $connection->end(Message::create('OK', $message->getPath(), $data));
        });
    }

    /**
     * Spawn a socket server up in a forked process.
     */
    public static function spawn(?LoggerInterface $logger = null)
    {
        static $spawned = false;

        // All children can use the same server so only one is required.
        if ($spawned) {
            return;
        }
        $spawned = true;
        // Ensure forks of this thread are not allowed to shutdown the server
        // prematurely.
        $authorised_pid = getmypid();


        $pid = pcntl_fork();

        if ($pid !== 0) {
            // When the parent is finished, send a signal to the server to close.
            register_shutdown_function(function () use ($logger, $authorised_pid) {
                if (getmypid() != $authorised_pid) {
                    return;
                }
                isset($logger) && $logger->info(getmypid().': Sending notification to server to halt.');
                Client::close();
            });

            // Wait for server to boot.
            usleep(1000);

            Client::register();

            // Return the pid of the server.
            return $pid;
        }

        register_shutdown_function(function () use ($logger) {
            isset($logger) && $logger->warning(getmypid().': Server is halting.');
        });

        return (new static($logger))->listen();
    }

    public function listen()
    {
        try {
            $socket = new SocketServer('127.0.0.1:8020');
        } catch (\RuntimeException $e) {
            $this->warning($e->getMessage().'. Will attempt to use existing server instead.');
            exit;
        }

        $socket->on('connection', function (ConnectionInterface $connection) use ($socket) {
            $this->debug('[' . $connection->getRemoteAddress() . ' connected]');

            $connection->on('data', function ($chunk) use ($connection, $socket) {
                try {
                    $data = '';
                    if (isset($this->buffer[$connection->getRemoteAddress()])) {
                        $data = $this->buffer[$connection->getRemoteAddress()];
                    }
                    $data .= $chunk;
                    // $this->debug('CHUNK: '.strlen($chunk). ' DATA: '.strlen($data));
                    $this->handleRequest(Message::fromPayload($data), $connection);
                    unset($this->buffer[$connection->getRemoteAddress()]);
                } catch (MessageException $e) {
                    $this->debug($e->getMessage()."; Buffering data chunk.");
                    $this->buffer[$connection->getRemoteAddress()] = $data;
                }

                if ($this->halt) {
                    $socket->close();
                }
            });

            $connection->on('close', function () use ($connection) {
                $this->debug('[' . $connection->getRemoteAddress() . ' disconnected]');
            });
        });

        $socket->on('error', function (\Exception $e) {
            $this->error('['.getmypid().']'.__FILE__.':'.__LINE__ . ' ' . $e->getMessage());
        });

        $this->info('Listening on ' . $socket->getAddress());
        Loop::run();
        exit;
    }

    public function handleRequest(Message $message, ConnectionInterface $connection)
    {
        $this->emit($message->getMethod(), [$message, $connection]);
    }

    /**
     * {@inheritdoc}
     */
    public function log($level, $message, array $context = array())
    {
        if (!isset($this->logger)) {
            return;
        }
        return $this->logger->log($level, get_class($this).'('.getmypid().'): '.$message, $context);
    }
}
