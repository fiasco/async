<?php
use Async\ForkManager;
use Async\ForkInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

require dirname(__DIR__).'/vendor/autoload.php';

$forkManager = new ForkManager();
$forkManager->setWaitTimeout(2);
$forkManager->setLogger(new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_NORMAL)));

// This will result in a caught fatal error from the child.
$forkManager->create()->run(fn() => @$foo->doNothing());

// This fork will catch a thrown exception and trigger the onError callback
// in the parent thread.
$forkManager->create()->run(function (ForkInterface $fork) {
  // You can set labels inside the fork to give better information about
  // what the specific fork is doing.
  $fork->setLabel("Fork that throws an error.");
  throw new \Exception('bar');
});

// This will result in a caught fatal error from the child.
$forkManager->create()->run(function (ForkInterface $fork) {
  $fork->setLabel("Fork that will timeout.");
  sleep(4);
});

// This fork will not error.
$forkManager->create()->run(fn() => 'baz');

echo "Waiting for forks to complete...\n";
// Wait for all forks to complete.
$forkManager->awaitForks();

// Loop over errored forks.
foreach ($forkManager->getForks(ForkInterface::STATUS_ERROR) as $fork) {
  // Result will be instance Async\Exception\ChildExceptionDetected.
  echo sprintf("Fork '%s' encountered an error:\n", $fork->getLabel());
  // echo $fork->getResult()->getMessage() . PHP_EOL;
}

foreach ($forkManager->getForks(ForkInterface::STATUS_COMPLETE) as $fork) {
  // Result will be instance Async\Exception\ChildExceptionDetected.
  echo sprintf("Fork '%s' completed.\n", $fork->getLabel());
  // echo $fork->getResult()->getMessage() . PHP_EOL;
}
