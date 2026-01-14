<?php

namespace Keepsuit\LaravelOpenTelemetry\WorkerMode\Detectors;

use Closure;
use Illuminate\Queue\Events\JobAttempted;
use Keepsuit\LaravelOpenTelemetry\WorkerMode\WorkerModeDetectorInterface;

/**
 * Detects Laravel Queue worker mode
 */
class QueueWorkerModeDetector implements WorkerModeDetectorInterface
{
    public function detect(): bool
    {
        return app()->runningConsoleCommand('queue:work')
            || app()->runningConsoleCommand('horizon:work');
    }

    public function onIterationEnded(Closure $callback): void
    {
        app('events')->listen(JobAttempted::class, $callback);
    }
}
