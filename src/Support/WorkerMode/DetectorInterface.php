<?php

namespace Keepsuit\LaravelOpenTelemetry\Support\WorkerMode;

/**
 * Detects the current worker mode (e.g., Octane, Horizon, Queue, standard HTTP requests)
 *
 * This interface allows for flexible worker mode detection, enabling the package to optimize
 * OpenTelemetry behavior based on the runtime context. Custom detectors can be implemented
 * to support additional worker modes.
 */
interface DetectorInterface
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
     *
     * @return string
     */
    public function getModeName(): string;
}
