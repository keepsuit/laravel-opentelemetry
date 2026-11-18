<?php

use Keepsuit\LaravelOpenTelemetry\Facades\OpenTelemetry;
use Keepsuit\LaravelOpenTelemetry\WorkerMode\WorkerModeDetectorInterface;
use Keepsuit\LaravelOpenTelemetry\WorkerMode\WorkerModeManager;
use Spatie\TestTime\TestTime;

test('flushes all signals based on interval', function () {
    TestTime::freezeAtSecond();

    OpenTelemetry::shouldReceive('flush')->once();

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

    new WorkerModeManager(
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
