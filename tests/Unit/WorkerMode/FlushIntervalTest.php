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
        flushInterval: 60,
        detectors: [$detector]
    );

    $detector->trigger();
    TestTime::addSecond();
    $detector->trigger();
    TestTime::addSeconds(60);
    $detector->trigger();
    TestTime::addSecond();
});

test('falls back to the legacy metrics_collect_interval config key', function (array $config, int $expected) {
    config()->set('opentelemetry.worker_mode', $config);
    app()->forgetInstance(WorkerModeManager::class);

    expect(invade(app(WorkerModeManager::class))->flushInterval)->toBe($expected);
})->with([
    'new key' => [['flush_interval' => 30, 'metrics_collect_interval' => 15], 30],
    'legacy key' => [['metrics_collect_interval' => 15], 15],
    'none' => [[], 60],
]);
