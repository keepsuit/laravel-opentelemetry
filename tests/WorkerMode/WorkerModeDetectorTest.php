<?php

use Keepsuit\LaravelOpenTelemetry\WorkerMode\Detectors\OctaneWorkerModeDetector;
use Keepsuit\LaravelOpenTelemetry\WorkerMode\WorkerModeManager;

test('detect mode with octane detector', function () {
    putenv('OCTANE_WORKERS=4');

    $detector = new WorkerModeManager([
        OctaneWorkerModeDetector::class,
    ]);

    expect($detector->isWorkerMode())->toBeTrue();

    putenv('OCTANE_WORKERS');
});

test('fallback to default detector', function () {
    $detector = new WorkerModeManager([
        OctaneWorkerModeDetector::class,
    ]);

    expect($detector->isWorkerMode())->toBeFalse();
});

test('caches detected result', function () {
    putenv('OCTANE_WORKERS=4');

    $detector = new WorkerModeManager([
        OctaneWorkerModeDetector::class,
    ]);

    $first = $detector->isWorkerMode();
    $second = $detector->isWorkerMode();

    expect($first)->toBe($second)->toBeTrue();

    putenv('OCTANE_WORKERS');
});
