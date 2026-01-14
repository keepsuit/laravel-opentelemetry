<?php

use Keepsuit\LaravelOpenTelemetry\Support\WorkerMode\Detectors\DefaultDetector;
use Keepsuit\LaravelOpenTelemetry\Support\WorkerMode\Detectors\OctaneDetector;
use Keepsuit\LaravelOpenTelemetry\Support\WorkerMode\WorkerModeDetector;

test('detect mode with octane detector', function () {
    putenv('OCTANE_WORKERS=4');
    
    $detector = new WorkerModeDetector([
        OctaneDetector::class,
        DefaultDetector::class,
    ]);

    expect($detector->detect())->toBe('octane');

    putenv('OCTANE_WORKERS');
});

test('fallback to default detector', function () {
    $detector = new WorkerModeDetector([
        DefaultDetector::class,
    ]);

    expect($detector->detect())->toBe('request');
});

test('caches detected mode', function () {
    putenv('OCTANE_WORKERS=4');
    
    $detector = new WorkerModeDetector([
        OctaneDetector::class,
        DefaultDetector::class,
    ]);

    $first = $detector->detect();
    $second = $detector->detect();

    expect($first)->toBe($second)->toBe('octane');

    putenv('OCTANE_WORKERS');
});

test('check if worker mode', function () {
    putenv('OCTANE_WORKERS=4');
    
    $detector = new WorkerModeDetector([
        OctaneDetector::class,
        DefaultDetector::class,
    ]);

    expect($detector->isWorkerMode())->toBeTrue();

    putenv('OCTANE_WORKERS');
});

test('get detected mode', function () {
    putenv('OCTANE_WORKERS=4');
    
    $detector = new WorkerModeDetector([
        OctaneDetector::class,
        DefaultDetector::class,
    ]);

    expect($detector->getDetectedMode())->toBe('octane');

    putenv('OCTANE_WORKERS');
});
