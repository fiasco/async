<?php

namespace Async;

use Seld\Signal\SignalHandler;

class Signal extends SignalHandler {

    protected static $callbacks = [];

    public static function register(array $signals, callable $callback)
    {
        foreach ($signals as $signal) {
            static::$callbacks[$signal][] = $callback;

            $calls = static::$callbacks[$signal];

            static::create($signal, function ($signal, $signalName) use ($calls) {
                foreach ($calls as $callback) {
                    $callback($signal, $signalName);
                }
                exit;
            });
        }
    }
}


 ?>
