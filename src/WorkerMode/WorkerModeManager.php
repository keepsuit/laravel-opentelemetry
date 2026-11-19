<?php

namespace Keepsuit\LaravelOpenTelemetry\WorkerMode;

use Carbon\Carbon;
use Keepsuit\LaravelOpenTelemetry\Facades\OpenTelemetry;

class WorkerModeManager
{
    protected int $lastFlushTimestamp;

    public function __construct(
        protected bool $flushAfterEachIteration = false,
        protected int $flushInterval = 60,
        /**
         * @var WorkerModeDetectorInterface[]
         */
        protected array $detectors = []
    ) {
        $this->lastFlushTimestamp = Carbon::now()->getTimestamp();

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
        if ($timestamp - $this->lastFlushTimestamp >= $this->flushInterval) {
            OpenTelemetry::flush();
            $this->lastFlushTimestamp = $timestamp;
        }
    }
}
