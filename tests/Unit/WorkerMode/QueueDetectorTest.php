<?php

use Keepsuit\LaravelOpenTelemetry\WorkerMode\Detectors\QueueDetector;

test('queue detector detects LARAVEL_QUEUE_WORKER env var set to 1', function () {
    putenv('LARAVEL_QUEUE_WORKER=1');

    $detector = new QueueDetector;

    expect($detector->detect())->toBeTrue();
    expect($detector->getModeName())->toBe('queue');

    putenv('LARAVEL_QUEUE_WORKER');
});

test('queue detector detects LARAVEL_QUEUE_WORKER env var set to true', function () {
    putenv('LARAVEL_QUEUE_WORKER=true');

    $detector = new QueueDetector;

    expect($detector->detect())->toBeTrue();

    putenv('LARAVEL_QUEUE_WORKER');
});

test('queue detector detects QUEUE env var', function () {
    putenv('QUEUE=default');

    $detector = new QueueDetector;

    expect($detector->detect())->toBeTrue();

    putenv('QUEUE');
});

test('queue detector returns false when not in queue', function () {
    $detector = new QueueDetector;

    expect($detector->detect())->toBeFalse();
});

test('queue detector mode name', function () {
    $detector = new QueueDetector;

    expect($detector->getModeName())->toBe('queue');
});
