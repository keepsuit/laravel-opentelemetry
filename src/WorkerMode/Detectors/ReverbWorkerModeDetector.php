<?php

namespace Keepsuit\LaravelOpenTelemetry\WorkerMode\Detectors;

use Closure;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Keepsuit\LaravelOpenTelemetry\WorkerMode\WorkerModeDetectorInterface;
use React\EventLoop\Loop;
use Throwable;

/**
 * Detects Laravel Reverb server, where an iteration is a periodic tick of the event loop
 */
class ReverbWorkerModeDetector implements WorkerModeDetectorInterface
{
    public function detect(): bool
    {
        // reverb:start is matched on CommandStarting: argv[1] can be an option (`artisan --host=0.0.0.0 reverb:start`).
        return class_exists(Loop::class) && app()->runningInConsole();
    }

    public function onIterationEnded(Closure $callback): void
    {
        app('events')->listen(CommandStarting::class, function (CommandStarting $event) use ($callback) {
            if ($event->command !== 'reverb:start') {
                return;
            }

            // The worker mode manager decides when an iteration flushes, so a short fixed tick is enough.
            $timer = Loop::addPeriodicTimer(1, function () use ($callback) {
                // An exception thrown inside a timer silently stops the Reverb server.
                try {
                    $callback();
                } catch (Throwable $e) {
                    report($e);
                }
            });

            // The global loop runs on shutdown if it never started: a pending timer would hang a failed `reverb:start`.
            app('events')->listen(CommandFinished::class, function (CommandFinished $event) use ($timer) {
                if ($event->command === 'reverb:start') {
                    Loop::cancelTimer($timer);
                }
            });
        });
    }
}
