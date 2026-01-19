<?php

namespace Keepsuit\LaravelOpenTelemetry\WorkerMode;

use Carbon\Carbon;
use Keepsuit\LaravelOpenTelemetry\Facades\Meter;
use Keepsuit\LaravelOpenTelemetry\Facades\OpenTelemetry;

class WorkerModeManager
{
    protected int $lastMetricsExportTimestamp;

    public function __construct(
        protected bool $flushAfterEachIteration = false,
        protected int $metricsExportInterval = 60,
        /**
         * @var WorkerModeDetectorInterface[]
         */
        protected array $detectors = []
    ) {
        $this->lastMetricsExportTimestamp = Carbon::now()->getTimestamp();

        $this->initDetectors();
    }

    protected function initDetectors(): void
    {
        foreach ($this->detectors as $detector) {
            if ($detector->detect()) {
                $detector->onIterationEnded(fn () => $this->handleIterationEnded());
            }
        }
    }

    protected function handleIterationEnded(): void
    {
        if ($this->flushAfterEachIteration) {
            OpenTelemetry::flush();

            return;
        }

        $timestamp = Carbon::now()->getTimestamp();
        if ($timestamp - $this->lastMetricsExportTimestamp >= $this->metricsExportInterval) {
            Meter::collect();
            $this->lastMetricsExportTimestamp = $timestamp;
        }
    }
}
