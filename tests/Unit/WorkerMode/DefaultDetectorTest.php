<?php

use Keepsuit\LaravelOpenTelemetry\WorkerMode\Detectors\DefaultDetector;

test('default detector always returns true', function () {
    $detector = new DefaultDetector;

    expect($detector->detect())->toBeTrue();
});

test('default detector mode name', function () {
    $detector = new DefaultDetector;

    expect($detector->getModeName())->toBe('request');
});
