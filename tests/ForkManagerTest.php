<?php

use PHPUnit\Framework\TestCase;
use Async\ForkManager;
use Psr\Log\NullLogger;
use Async\Exception\ForkException;


final class ForkManagerTest extends TestCase
{

  public function testRun()
  {
    $manager = new ForkManager(null, new NullLogger());
    $manager->run(fn() => 'foo');

    foreach ($manager->receive() as $payload) {
      $this->assertEquals($payload, 'foo');
    }

    $manager->run(fn() => 'bar');
    $manager->run(fn() => 'baz');

    $tags = [];
    foreach ($manager->receive() as $payload) {
      $tags[] = $payload;
    }

    $this->assertEquals($tags[0], 'bar');
    $this->assertEquals($tags[1], 'baz');

    $tags = [];
    foreach ($manager->receive() as $payload) {
      $tags[] = $payload;
    }

    $this->assertEmpty($tags);

    $manager->run(fn() => true);
    $manager->run(fn() => null);
    $manager->run(fn() => 'null');
    $manager->run(fn() => false);
    $manager->run(fn() => 0);
    $manager->run(fn() => 1);
    $manager->run(fn() => new stdClass);
    $manager->run(fn() => []);

    $tags = [];
    foreach ($manager->receive() as $payload) {
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


    $manager->run(function () {
      throw new \Exception("Test exception.");
    });

    $this->expectException(ForkException::class);

    foreach ($manager->receive() as $payload) {
      $tags[] = $payload;
    }
  }

  public function testAsyncRun() {
    $manager = new ForkManager(null, new NullLogger());

    $manager->run(function () {
      sleep(3);
      return 'foo';
    });

    $manager->run(function () {
      return 'bar';
    });

    $tags = [];
    foreach ($manager->receive() as $payload) {
      $tags[] = $payload;
    }

    $this->assertEquals($tags[0], 'bar');
    $this->assertEquals($tags[1], 'foo');
  }

  public function testHighVolumeRun() {
    $manager = new ForkManager(null, new NullLogger());

    for ($i=0; $i < 100; $i++) {
      $manager->run(function () use ($i) {
        usleep(mt_rand(0, 1000));
        return 'fork ' . $i;
      });
    }

    $tags = [];
    foreach ($manager->receive() as $payload) {
      $tags[] = $payload;
    }

    $this->assertEquals(count($tags), 100);

    $manager2 = new ForkManager(null, new NullLogger());

    for ($i=0; $i < 100; $i++) {
      $manager->run(function () use ($i) {
        usleep(mt_rand(0, 1000));
        return 'fork-1.' . $i;
      });

      $manager2->run(function () use ($i) {
        usleep(mt_rand(0, 1000));
        return 'fork-2.' . $i;
      });
    }

    $tags = [];
    foreach ($manager2->receive() as $payload) {
      $tags[] = $payload;
    }

    foreach ($manager->receive() as $payload) {
      $tags[] = $payload;
    }

    $this->assertEquals(count($tags), 200);
  }

  public function testInceptionRun() {
    $manager = new ForkManager(null, new NullLogger());

    $manager->run(function () {
      $manager2 = new ForkManager(null, new NullLogger());
      $manager2->run(fn() => 'foo');
      foreach ($manager2->receive() as $payload) {
        $this->assertEquals($payload, 'foo');
      }
      return 'bar';
    });

    foreach ($manager->receive() as $payload) {
      $this->assertEquals($payload, 'bar');
    }
  }

}
