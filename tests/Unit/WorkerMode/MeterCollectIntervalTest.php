<?php

use Keepsuit\LaravelOpenTelemetry\Facades\Meter;
use Keepsuit\LaravelOpenTelemetry\WorkerMode\WorkerModeDetectorInterface;
use Spatie\TestTime\TestTime;

test('calls meter collect based on interval', function () {
    TestTime::freezeAtSecond();

    Meter::shouldReceive('collect')->once();

    $detector = new class implements WorkerModeDetectorInterface
    {
        protected Closure $callback;

        public function detect(): bool
        {
            return true;
        }

        public function onIterationEnded(Closure $callback): void
        {
            $this->callback = $callback;
        }

        public function trigger(): void
        {
            $this->callback->__invoke();
        }
    };

    new \Keepsuit\LaravelOpenTelemetry\WorkerMode\WorkerModeManager(
        flushAfterEachIteration: false,
        metricsExportInterval: 60,
        detectors: [$detector]
    );

    $detector->trigger();
    TestTime::addSecond();
    $detector->trigger();
    TestTime::addSeconds(60);
    $detector->trigger();
    TestTime::addSecond();
});
