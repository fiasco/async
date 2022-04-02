<?php
use Async\ForkManager;
use Async\ForkInterface;

require dirname(__DIR__).'/vendor/autoload.php';

$forkManager = new ForkManager();
// Wait up to 3 seconds for fork response before giving up.
$forkManager->setWaitTimeout(3);

$forkManager->create()->run(fn() => 'foo');
// This will not show unless true is passed as ->getForkResults(true).
// This will cause a fatal error in the child that will not report back
// and will therefore rely on the forkManager timeout to error out.
$forkManager->create()->run(fn() => new \Exception('bar'));
$forkManager->create()->run(fn() => 'baz');

echo "Waiting for forks to complete...\n";
// Wait for all forks to complete.
$forkManager->awaitForks();

// Loop over errored forks.
foreach ($forkManager->getForks(ForkInterface::STATUS_ERROR) as $fork) {
  // Result will be instance Async\Exception\ChildExceptionDetected.
  echo sprintf("Fork '%s' encountered an error:\n", $fork->getLabel());
  echo $fork->getResult()->getMessage() . PHP_EOL;
}
