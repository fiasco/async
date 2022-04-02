<?php
use Async\ForkManager;
use Async\ForkInterface;
use Async\Exception\ChildExceptionDetected;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

require dirname(__DIR__).'/vendor/autoload.php';

$forkManager = new ForkManager();
$forkManager->setLogger(new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_VERY_VERBOSE)));

$forkManager->create()
->run(function (ForkInterface $fork) {
  return file_get_contents('https://google.com/');
})
->onSuccess(function ($html) {
  echo "Got website with a length of ".strlen($html)." bytes\n";
})
->onError(function (ChildExceptionDetected $e) {
  echo "ERROR: ".$e->getMessage().PHP_EOL;
});

// Block on main thread until all forks have completed.
$forkManager->awaitForks();
