<?php

use Keepsuit\LaravelOpenTelemetry\OpenTelemetry;
use Keepsuit\LaravelOpenTelemetry\WorkerMode\WorkerModeDetector;

test('worker mode detector is registered in container', function () {
    $detector = app(WorkerModeDetector::class);

    expect($detector)->toBeInstanceOf(WorkerModeDetector::class);
});

test('default mode is request', function () {
    $detector = app(WorkerModeDetector::class);

    expect($detector->getDetectedMode())->toBe('request');
});

test('octane mode is detected', function () {
    putenv('OCTANE_WORKERS=4');

    // Recreate detector with fresh detection
    $detectorClasses = config('opentelemetry.worker_mode.detectors', []);
    $detectorClasses[] = \Keepsuit\LaravelOpenTelemetry\WorkerMode\Detectors\DefaultDetector::class;
    $detector = new WorkerModeDetector($detectorClasses);

    expect($detector->getDetectedMode())->toBe('octane');

    putenv('OCTANE_WORKERS');
});

test('opentelemetry facade has flush method', function () {
    $otel = app(OpenTelemetry::class);

    expect(method_exists($otel, 'flush'))->toBeTrue();
});

test('opentelemetry facade has shutdown method', function () {
    $otel = app(OpenTelemetry::class);

    expect(method_exists($otel, 'shutdown'))->toBeTrue();
});

test('opentelemetry facade can check if running in worker mode', function () {
    $otel = app(OpenTelemetry::class);

    expect($otel->isRunningInWorkerMode())->toBeBool();
});

test('opentelemetry facade can get detected mode', function () {
    $otel = app(OpenTelemetry::class);

    expect($otel->getDetectedMode())->toBe('request');
});

test('flush completes without error', function () {
    $otel = app(OpenTelemetry::class);

    // Should not throw any exception
    $otel->flush();

    expect(true)->toBeTrue();
});

test('shutdown completes without error', function () {
    $otel = app(OpenTelemetry::class);

    // Should not throw any exception
    $otel->shutdown();

    expect(true)->toBeTrue();
});

test('worker mode is disabled by default', function () {
    $workerModeEnabled = config('opentelemetry.worker_mode.enabled', false);

    expect($workerModeEnabled)->toBeFalse();
});

test('worker mode detectors are configured', function () {
    $detectors = config('opentelemetry.worker_mode.detectors', []);

    expect($detectors)->toBeArray();
    expect(count($detectors))->toBeGreaterThanOrEqual(0);
});

test('worker mode can be enabled for optimization', function () {
    config()->set('opentelemetry.worker_mode.enabled', true);
    $workerModeEnabled = config('opentelemetry.worker_mode.enabled');

    expect($workerModeEnabled)->toBeTrue();
});
