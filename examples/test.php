<?php

use Async\ForkManager;
use Async\React\Client;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

require 'vendor/autoload.php';

$logger = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_VERY_VERBOSE));
$forkManager = new ForkManager();
$forkManager->setLogger($logger);
Client::setLogger($logger);

$sizes = [64, 128, 256, 512, 1024];

function generateBytes(int $length) {
  $bytes = '';
  while (strlen($bytes) < $length) {
    $bytes .= mt_rand(1, $length);
  }
  return substr($bytes, 0, $length);
}

foreach ($sizes as $size) {
  $size = $size * 1024 * 8;
  $value = generateBytes($size);

  $forkManager->create()
    ->run(function () use ($value, $size) {
      usleep(mt_rand(1, $size));
      return [$value];
    })
    ->onSuccess(function ($data) use ($size, $value) {
      echo "Got data: ".strlen($data[0])." expecting $size.\n";
      echo ($data[0] == $value) ? "Same value\n" : "Different value\n";
    })
    ->onError(function ($e) use ($logger) {
      $logger->error($e->getMessage());
    });
}

$forkManager->awaitForks();


 ?>
