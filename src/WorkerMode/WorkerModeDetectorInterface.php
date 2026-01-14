<?php

namespace Keepsuit\LaravelOpenTelemetry\WorkerMode;

use Closure;

/**
 * Detects if the application is running in worker mode
 */
interface WorkerModeDetectorInterface
{
    /**
     * Detect if the application is running in this worker mode
     *
     * @return bool True if the mode is detected, false otherwise
     */
    public function detect(): bool;

    /**
     * Register a callback to be executed at the end of each iteration of the worker loop
     */
    public function onIterationEnded(Closure $callback): void;
}
