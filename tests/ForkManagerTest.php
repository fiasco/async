<?php

use PHPUnit\Framework\TestCase;
use Async\ForkManager;
use Psr\Log\NullLogger;
use Async\Exception\ForkException;
use Async\ForkInterface;

final class ForkManagerTest extends TestCase
{

  public function testSingle():void
  {
    $manager = new ForkManager();
    $manager->create()->run(fn() => 'foo');

    foreach ($manager->getForkResults() as $payload) {
      $this->assertEquals($payload, 'foo');
    }
  }

  public function testMultiple():void
  {

    $manager = new ForkManager();

    $manager->create()->run(fn() => 'bar');
    $manager->create()->run(fn() => 'baz');

    $tags = [];
    foreach ($manager->getForkResults() as $payload) {
      $tags[] = $payload;
    }
    $this->assertEquals(count($tags), 2);
    $this->assertEquals($tags[0], 'bar');
    $this->assertEquals($tags[1], 'baz');

    $manager->create()->run(fn() => 'foo');

    $tags = [];
    foreach ($manager->getForkResults() as $payload) {
      $tags[] = $payload;
    }

    $this->assertEquals(count($tags), 3);
    $this->assertEquals($tags[2], 'foo');

  }

  public function testSynchronousOrder():void
  {
    $manager = new ForkManager();
    $manager->setAsync(false);

    $manager->create()->run(fn() => true);
    $manager->create()->run(fn() => null);
    $manager->create()->run(fn() => 'null');
    $manager->create()->run(fn() => false);
    $manager->create()->run(fn() => 0);
    $manager->create()->run(fn() => 1);
    $manager->create()->run(fn() => new stdClass);
    $manager->create()->run(fn() => []);

    $tags = [];
    foreach ($manager->getForkResults() as $payload) {
      $tags[] = $payload;
    }

    $this->assertEquals(count($tags), 8);
    $this->assertSame($tags[0], true);
    $this->assertSame($tags[1], null);
    $this->assertSame($tags[2], 'null');
    $this->assertSame($tags[3], false);
    $this->assertSame($tags[4], 0);
    $this->assertSame($tags[5], 1);
    $this->assertEquals($tags[6], new stdClass);
    $this->assertSame($tags[7], []);
  }

  public function testForkExceptions():void
  {
    $manager = new ForkManager();

    $manager->create()->run(function () {
      throw new \Exception("Test exception.");
    });
    $manager->awaitForks();
    $this->assertTrue($manager->hasErrors(), "ForkManager containers fork with error.");
  }

  public function testAsyncRun():void 
  {
    // Cannot test async on runtimes without pcntl.
    if (!function_exists('pcntl_fork')) {
      $this->assertTrue(false, "pcntl extension is enabled.");
      return;
    }

    $manager = new ForkManager();

    $manager->create()
      ->onSuccess(function($r) {
        $this->assertEquals($r, 'foo');
      })
      ->run(function () {
        sleep(3);
        return 'foo';
      });

    $manager->create()
      ->onSuccess(function($r) {
        $this->assertEquals($r, 'bar');
      })
      ->run(function () {
        return 'bar';
      });

    $manager->awaitForks();
  }

  public function testHighVolumeRun():void
  {
    $manager = new ForkManager();

    for ($i=0; $i < 100; $i++) {
      $manager->create()->run(function () use ($i) {
        usleep(mt_rand(0, 1000));
        return 'fork ' . $i;
      });
    }

    $tags = [];
    foreach ($manager->getForkResults() as $payload) {
      $tags[] = $payload;
    }

    $this->assertEquals(count($tags), 100);
  }

  public function testDualForkManagers():void 
  {
    $manager = new ForkManager();
    $manager2 = new ForkManager();

    for ($i=0; $i < 100; $i++) {
      $manager->create()->run(function () use ($i) {
        usleep(mt_rand(0, 1000));
        return 'fork-1.' . $i;
      });

      $manager2->create()->run(function () use ($i) {
        usleep(mt_rand(0, 1000));
        return 'fork-2.' . $i;
      });
    }

    $tags = [];
    foreach ($manager2->getForkResults() as $payload) {
      $tags[] = $payload;
    }

    foreach ($manager->getForkResults() as $payload) {
      $tags[] = $payload;
    }

    $this->assertEquals(count($tags), 200);
  }

  public function testInceptionRun():void 
  {
    $manager = new ForkManager();

    $manager->create()->run(function () {
      $manager2 = new ForkManager();
      $manager2->create()->run(fn() => 'foo');
      foreach ($manager2->getForkResults() as $payload) {
        $this->assertEquals($payload, 'foo');
      }
      return 'bar';
    });

    foreach ($manager->getForkResults() as $payload) {
      $this->assertEquals($payload, 'bar');
    }
  }

  public function testLargePayloads():void
  {
    $manager = new ForkManager();
    $manager->create()->run(fn() => $this->generateRandomString());
    $manager->create()->run(function () {
      usleep(10000);
      return $this->generateRandomString();
    });
    $manager->create()->run(fn() => $this->generateRandomString());

    foreach ($manager->getForkResults() as $payload) {
      $tags[] = $payload;
    }
    $this->assertEquals(count($tags), 3);
  }

  public function testWaitTimeoutException():void
  {
    $manager = new ForkManager();
    $manager->setWaitTimeout(2);
    $manager->create()->run(fn() => sleep(4));

    $manager->awaitForks();
    $this->assertTrue($manager->hasErrors(),"Fork encounted a timeout error (4s > 2s).");
  }

  protected function generateRandomString($length = 1048576):string 
  {
      $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
      $charactersLength = strlen($characters);
      $randomString = '';
      for ($i = 0; $i < $length; $i++) {
          $randomString .= $characters[rand(0, $charactersLength - 1)];
      }
      return $randomString;
  }





}
