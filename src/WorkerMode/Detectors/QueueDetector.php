<?php

namespace Keepsuit\LaravelOpenTelemetry\WorkerMode\Detectors;

use Keepsuit\LaravelOpenTelemetry\WorkerMode\DetectorInterface;

/**
 * Detects Laravel Queue worker mode
 *
 * Checks for:
 * - LARAVEL_QUEUE_WORKER environment variable
 * - QUEUE environment variable indicating queue context
 * - Running as a queue worker process
 */
class QueueDetector implements DetectorInterface
{
    public function detect(): bool
    {
        // Check for LARAVEL_QUEUE_WORKER env var (set by Laravel queue worker)
        $queueWorker = env('LARAVEL_QUEUE_WORKER');
        if ($queueWorker !== null && in_array((string) $queueWorker, ['1', 'true', 'yes'], true)) {
            return true;
        }

        // Check for QUEUE env var indicating queue context
        if (env('QUEUE') !== null) {
            return true;
        }

        // Check if running as queue worker by checking parent process
        if (function_exists('posix_getppid')) {
            $ppid = posix_getppid();
            if ($ppid !== false) {
                $parentName = shell_exec("ps -p {$ppid} -o comm=");
                if ($parentName && stripos($parentName, 'queue') !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getModeName(): string
    {
        return 'queue';
    }
}
