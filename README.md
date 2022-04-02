# Async

PHP library to execute tasks asynchronously.

This library allows you to hand off anonymous functions to execute in parallel
through child forks using the PCNTL extension.

Return values of the anonymous functions are serialized and returned to the
the parent fork to process.

**Note:** Objects that cannot be serialized are not supported as return values.

**Note:** As Exception objects can contain objects in their stack trace that cannot be
serialized, it is not recommended use them as return values for the onSuccess
callback function.

If you're wanting to run asynchronous activities like requests file operations
or server interaction, consider [ReactPHP](https://reactphp.org) instead (which
this library relies upon).

## Installation

Recommended installation method via composer.
```
composer require fiasco/async:^3.0
```

## Usage

See [examples](examples).

Use the fork manager to create forks to execute in parallel.

```php
<?php
use Async\ForkManager;
use Async\ForkInterface;
use Async\Exception\ChildExceptionDetected;

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
```

You can also chain calls together:

```php
<?php
use Async\ForkManager;
use Async\ForkInterface;
use Async\Exception\ChildExceptionDetected;

$forkManager = new ForkManager();

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
```

If you prefer to work with the results in a foreach loop, you can use
`ForkManager->getForkResults()` to return the results. To include errors, pass
`true` as the first argument into the function.

```php
<?php
use Async\ForkManager;

$forkManager = new ForkManager();
// Wait up to 3 seconds for fork response before giving up.
$forkManager->setWaitTimeout(3);

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

// Waiting for the fork in parent will timeout and force the fork
// to error out. The Fork child will still attempt to return the
// result but it will fail.
$forkManager->create()->run(function (ForkInterface $fork) {
  $fork->setLabel("Fork that will timeout.");
  sleep(4);
  return 'fuz';
});

$forkManager->create()->run(fn() => 'baz');

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
```

Alternative to the onError callback, you can also get all errored forks using the
getForks() method.

```php
<?php

use Async\ForkInterface;

// .. create forks.

// Wait for all forks to complete.
$forkManager->awaitForks();

// Loop over errored forks.
foreach ($forkManager->getForks(ForkInterface::STATUS_ERROR) as $fork) {
  // Result will be instance Async\Exception\ChildExceptionDetected.
  echo sprintf("Fork '%s' encountered an error:\n", $fork->getLabel());
  echo $fork->getResult()->getMessage() . PHP_EOL;
}
```
