<?php

namespace Keepsuit\LaravelOpenTelemetry\WorkerMode\Detectors;

use Keepsuit\LaravelOpenTelemetry\WorkerMode\DetectorInterface;

/**
 * Detects Laravel Octane worker mode
 *
 * Checks for:
 * - OCTANE_WORKERS environment variable
 * - Running in a supervised worker context
 * - Parent process name containing 'octane'
 */
class OctaneDetector implements DetectorInterface
{
    public function detect(): bool
    {
        // Check for OCTANE_WORKERS env var (set by octane supervisor)
        if (env('OCTANE_WORKERS') !== null) {
            return true;
        }

        // Check for OCTANE_SERVER env var (set by octane itself)
        if (env('OCTANE_SERVER') !== null) {
            return true;
        }

        // Check if running as octane worker by checking parent process
        if (function_exists('posix_getppid')) {
            $ppid = posix_getppid();
            if ($ppid !== false) {
                $parentName = shell_exec("ps -p {$ppid} -o comm=");
                if ($parentName && stripos($parentName, 'octane') !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getModeName(): string
    {
        return 'octane';
    }
}
