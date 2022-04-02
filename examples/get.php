<?php

use Async\React\Client;

require 'vendor/autoload.php';

while (true) {
  $message = Client::get('/test/foo');
  echo $message->getMethod() . " " . $message->getPath() . ": " . $message->getPayload() . PHP_EOL;

  $message = Client::get('/test/bar');
  echo $message->getMethod() . " " . $message->getPath() . ": " . $message->getPayload() . PHP_EOL;

  $message = Client::get('/test/baz');
  echo $message->getMethod() . " " . $message->getPath() . ": " . $message->getPayload() . PHP_EOL;

  usleep(48000);
}


 ?>
