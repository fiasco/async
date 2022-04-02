<?php

use Async\React\Client;
use Async\React\Message;

require 'vendor/autoload.php';

// Client::put('/test/foo', 'bar', function (Message $message) {
//   echo $message->getMethod() . " " . $message->getPath() . ": " . $message->getPayload() . PHP_EOL;
// });

while (true) {
  $message = Client::put('/test/foo', 'foo'.mt_rand(1,100));
  echo $message->getMethod() . " " . $message->getPath() . ": " . $message->getPayload() . PHP_EOL;

  $message = Client::put('/test/bar', 'bar'.mt_rand(1,100));
  echo $message->getMethod() . " " . $message->getPath() . ": " . $message->getPayload() . PHP_EOL;

  $message = Client::put('/test/baz', 'baz'.mt_rand(1,100));
  echo $message->getMethod() . " " . $message->getPath() . ": " . $message->getPayload() . PHP_EOL;

  usleep(50000);
}


 ?>
