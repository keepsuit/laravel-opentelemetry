<?php

namespace Keepsuit\LaravelOpenTelemetry\Support\WorkerMode;

/**
 * Orchestrates worker mode detection by running configured detectors in order
 *
 * The first detector that returns true determines the detected worker mode.
 * If no detector matches, falls back to the DefaultDetector.
 */
class WorkerModeDetector
{
    /**
     * @var string|null
     */
    private ?string $detectedMode = null;

    /**
     * @var bool
     */
    private bool $detected = false;

    /**
     * @param array<class-string<DetectorInterface>> $detectors
     */
    public function __construct(
        private array $detectors = [],
    ) {
    }

    /**
     * Detect the current worker mode
     *
     * @return string The detected mode name (e.g., 'octane', 'horizon', 'queue', 'request')
     */
    public function detect(): string
    {
        if ($this->detected) {
            return $this->detectedMode ?? 'request';
        }

        $this->detected = true;

        foreach ($this->detectors as $detectorClass) {
            if (! class_exists($detectorClass)) {
                continue;
            }

            $detector = app($detectorClass);

            if (! $detector instanceof DetectorInterface) {
                continue;
            }

            if ($detector->detect()) {
                $this->detectedMode = $detector->getModeName();

                return $this->detectedMode;
            }
        }

        // Fallback to DefaultDetector
        $defaultDetector = app(Detectors\DefaultDetector::class);
        $this->detectedMode = $defaultDetector->getModeName();

        return $this->detectedMode;
    }

    /**
     * Check if the application is running in a worker mode
     *
     * Worker modes are long-running processes like Octane, Horizon, or Queue.
     *
     * @return bool
     */
    public function isWorkerMode(): bool
    {
        return $this->detect() !== 'request';
    }

    /**
     * Get the detected worker mode name
     *
     * @return string
     */
    public function getDetectedMode(): string
    {
        return $this->detect();
    }
}

