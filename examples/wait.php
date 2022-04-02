<?php

use Async\React\Client;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

require 'vendor/autoload.php';

$logger = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_VERY_VERBOSE));

Client::setLogger($logger);
Client::register();

sleep(10);

Client::close();

 ?>
