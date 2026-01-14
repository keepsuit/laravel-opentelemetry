<?php

namespace Keepsuit\LaravelOpenTelemetry\WorkerMode\Detectors;

use Keepsuit\LaravelOpenTelemetry\WorkerMode\DetectorInterface;

/**
 * Fallback detector for standard HTTP request mode
 *
 * This detector always returns true and should be the last detector in the list.
 * It represents the default behavior for standard HTTP request/response cycles.
 */
class DefaultDetector implements DetectorInterface
{
    public function detect(): bool
    {
        return true;
    }

    public function getModeName(): string
    {
        return 'request';
    }
}
