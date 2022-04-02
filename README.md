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

## Installation

Recommended installation method via composer.
```
composer require fiasco/async:^3.0
```

## Usage

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

$forkManager->create()->run(fn() => 'foo');
// This will not show unless true is passed as ->getForkResults(true).
$forkManager->create()->run(fn() => throw new \Exception('bar'));
$forkManager->create()->run(fn() => 'baz');

foreach ($forkManager->getForkResults() as $result) {
  // Will echo foo, baz. Ordered in the same sequence the forks were created.
  echo "$result\n";
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
  echo $fork->getResult()->getMessage() . PHP_EOL;
}
```
