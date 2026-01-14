<?php

namespace Keepsuit\LaravelOpenTelemetry\WorkerMode;

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
     * Get the name/identifier of this worker mode
     *
     * Examples: 'octane', 'horizon', 'queue', 'request'
     */
    public function getModeName(): string;
}
