<?php

namespace Keepsuit\LaravelOpenTelemetry\WorkerMode;

/**
 * Orchestrates worker mode detection by running configured detectors in order
 *
 * The first detector that returns true determines the detected worker mode.
 * If no detector matches, falls back to the DefaultDetector.
 */
class WorkerModeManager
{
    private ?bool $detected = null;

    /**
     * @param  array<class-string<WorkerModeDetectorInterface>>  $detectors
     */
    public function __construct(
        private array $detectors = [],
    ) {}

    /**
     * Check if the application is running in a worker mode
     */
    public function isWorkerMode(): bool
    {
        if ($this->detected !== null) {
            return $this->detected;
        }

        foreach ($this->detectors as $detectorClass) {
            if (! class_exists($detectorClass)) {
                continue;
            }

            $detector = app($detectorClass);

            if (! $detector instanceof WorkerModeDetectorInterface) {
                continue;
            }

            if ($detector->detect()) {
                return $this->detected = true;
            }
        }

        return $this->detected = false;
    }
}
