<?php
use Async\ForkManager;
use Async\ForkInterface;
use Async\Exception\ChildExceptionDetected;

require dirname(__DIR__).'/vendor/autoload.php';

$forkManager = new ForkManager();

// Return an instance of ForkInterface.
// Will be either a SynchronousFork or an AsynchronousFork if PCNTL extension
// is enabled.
$fork = $forkManager->create();

// This anonymous function will run inside a child fork.
$fork->run(function (ForkInterface $fork) {
  return file_get_contents('https://google.com/');
});

// Return  contents from run function will be provided as the input argument
// to the onSuccess callback. This anonymous function is run in the parent thread.
$fork->onSuccess(function ($html) {
  echo "Got website with a length of ".strlen($html)." bytes\n";
});

// On error is called when the child process encounters an uncaught throwable.
$fork->onError(function (ChildExceptionDetected $e) {
  echo "ERROR: ".$e->getMessage().PHP_EOL;
});

// Block on main thread until all forks have completed.
$forkManager->awaitForks();
