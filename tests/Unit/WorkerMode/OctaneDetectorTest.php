<?php

use Keepsuit\LaravelOpenTelemetry\Support\WorkerMode\Detectors\OctaneDetector;

test('octane detector detects OCTANE_WORKERS env var', function () {
    putenv('OCTANE_WORKERS=4');

    $detector = new OctaneDetector;

    expect($detector->detect())->toBeTrue();
    expect($detector->getModeName())->toBe('octane');

    putenv('OCTANE_WORKERS');
});

test('octane detector detects OCTANE_SERVER env var', function () {
    putenv('OCTANE_SERVER=127.0.0.1:8000');

    $detector = new OctaneDetector;

    expect($detector->detect())->toBeTrue();
    expect($detector->getModeName())->toBe('octane');

    putenv('OCTANE_SERVER');
});

test('octane detector returns false when not in octane', function () {
    $detector = new OctaneDetector;

    expect($detector->detect())->toBeFalse();
});

test('octane detector mode name', function () {
    $detector = new OctaneDetector;

    expect($detector->getModeName())->toBe('octane');
});
