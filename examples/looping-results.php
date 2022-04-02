<?php
use Async\ForkManager;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

require dirname(__DIR__).'/vendor/autoload.php';

$forkManager = new ForkManager();
// Wait up to 3 seconds for fork response before giving up.
$forkManager->setWaitTimeout(3);
$forkManager->setLogger(new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_NORMAL)));

$forkManager->create()->run(fn() => 'foo');
// This will not show unless true is passed as ->getForkResults(true).
// This will cause a fatal error in the child that will not report back
// and will therefore rely on the forkManager timeout to error out.
$forkManager->create()->run(fn() => new \Exception('bar'));
$forkManager->create()->run(fn() => 'baz');

echo "Waiting for forks to complete...\n";
foreach ($forkManager->getForkResults() as $result) {
  // Will echo foo, baz. Ordered in the same sequence the forks were created.
  echo "$result\n";
}
