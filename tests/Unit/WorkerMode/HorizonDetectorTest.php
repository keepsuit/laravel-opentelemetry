<?php

use Keepsuit\LaravelOpenTelemetry\WorkerMode\Detectors\HorizonDetector;

test('horizon detector detects HORIZON env var set to true', function () {
    putenv('HORIZON=true');

    $detector = new HorizonDetector;

    expect($detector->detect())->toBeTrue();
    expect($detector->getModeName())->toBe('horizon');

    putenv('HORIZON');
});

test('horizon detector detects HORIZON env var set to 1', function () {
    putenv('HORIZON=1');

    $detector = new HorizonDetector;

    expect($detector->detect())->toBeTrue();

    putenv('HORIZON');
});

test('horizon detector detects HORIZON_POOL env var', function () {
    putenv('HORIZON_POOL=default');

    $detector = new HorizonDetector;

    expect($detector->detect())->toBeTrue();

    putenv('HORIZON_POOL');
});

test('horizon detector returns false when not in horizon', function () {
    $detector = new HorizonDetector;

    expect($detector->detect())->toBeFalse();
});

test('horizon detector mode name', function () {
    $detector = new HorizonDetector;

    expect($detector->getModeName())->toBe('horizon');
});
