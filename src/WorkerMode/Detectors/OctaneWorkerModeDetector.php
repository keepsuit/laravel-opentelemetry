<?php

namespace Keepsuit\LaravelOpenTelemetry\WorkerMode\Detectors;

use Closure;
use Keepsuit\LaravelOpenTelemetry\WorkerMode\WorkerModeDetectorInterface;
use Laravel\Octane\Events\RequestTerminated;

/**
 * Detects Laravel Octane worker mode
 */
class OctaneWorkerModeDetector implements WorkerModeDetectorInterface
{
    public function detect(): bool
    {
        $octane = $_SERVER['LARAVEL_OCTANE'] ?? null;

        return $octane === '1' || $octane === 'true';
    }

    public function onIterationEnded(Closure $callback): void
    {
        app('events')->listen(RequestTerminated::class, $callback);
    }
}
