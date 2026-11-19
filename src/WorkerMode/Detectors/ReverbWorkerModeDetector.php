<?php

namespace Keepsuit\LaravelOpenTelemetry\WorkerMode\Detectors;

use Closure;
use Illuminate\Console\Events\CommandFinished;
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
        return class_exists(Loop::class) && app()->runningConsoleCommand('reverb:start');
    }

    public function onIterationEnded(Closure $callback): void
    {
        // The worker mode manager decides when an iteration flushes, so a short fixed tick is enough.
        $timer = Loop::addPeriodicTimer(
            1,
            function () use ($callback) {
                // An exception thrown inside a timer silently stops the Reverb server.
                try {
                    $callback();
                } catch (Throwable $e) {
                    report($e);
                }
            },
        );

        // The global loop runs on shutdown if it never started: a pending timer would hang a failed `reverb:start`.
        app('events')->listen(CommandFinished::class, function (CommandFinished $event) use ($timer) {
            if ($event->command === 'reverb:start') {
                Loop::cancelTimer($timer);
            }
        });
    }
}
