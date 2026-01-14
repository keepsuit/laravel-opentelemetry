<?php

namespace Keepsuit\LaravelOpenTelemetry\WorkerMode\Detectors;

use Keepsuit\LaravelOpenTelemetry\WorkerMode\WorkerModeDetectorInterface;

/**
 * Detects Laravel Horizon worker mode
 *
 * Checks for:
 * - HORIZON environment variable (set by Horizon supervisor)
 * - Running in a supervised worker context
 * - Parent process name containing 'horizon'
 */
class HorizonWorkerModeDetector implements WorkerModeDetectorInterface
{
    public function detect(): bool
    {
        // Check for HORIZON env var (set by horizon supervisor)
        $horizonEnv = env('HORIZON');
        if ($horizonEnv !== null && in_array((string) $horizonEnv, ['true', '1', 'yes'], true)) {
            return true;
        }

        // Check for HORIZON_POOL env var
        if (env('HORIZON_POOL') !== null) {
            return true;
        }

        // Check if running as horizon worker by checking parent process
        if (function_exists('posix_getppid')) {
            $ppid = posix_getppid();
            if ($ppid !== false) {
                $parentName = shell_exec("ps -p {$ppid} -o comm=");
                if ($parentName && stripos($parentName, 'horizon') !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getModeName(): string
    {
        return 'horizon';
    }
}
