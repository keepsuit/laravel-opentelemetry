<?php

use Illuminate\Queue\Events\JobAttempted;
use Keepsuit\LaravelOpenTelemetry\WorkerMode\Detectors\QueueWorkerModeDetector;

test('detects when queue:work is running', function () {
    $ORIGINAL_SERVER = $_SERVER;

    $_SERVER['APP_RUNNING_IN_CONSOLE'] = '1';
    $_SERVER['argv'] = ['artisan', 'queue:work'];

    $detector = new QueueWorkerModeDetector;
    expect($detector->detect())->toBeTrue();

    $_SERVER = $ORIGINAL_SERVER;
});

test('detects when horizon:work is running', function () {
    $ORIGINAL_SERVER = $_SERVER;

    $_SERVER['APP_RUNNING_IN_CONSOLE'] = '1';
    $_SERVER['argv'] = ['artisan', 'horizon:work'];

    $detector = new QueueWorkerModeDetector;
    expect($detector->detect())->toBeTrue();

    $_SERVER = $ORIGINAL_SERVER;
});

test('does not detect when neither queue:work nor horizon:work is running', function () {
    $detector = new QueueWorkerModeDetector;
    expect($detector->detect())->toBeFalse();
});

test('registers callback for JobAttempted event', function () {
    $detector = new QueueWorkerModeDetector;
    $called = false;

    $detector->onIterationEnded(function () use (&$called) {
        $called = true;
    });

    // Create a mock job that satisfies the JobAttempted event constructor
    $mockJob = Mockery::mock(Illuminate\Contracts\Queue\Job::class);
    $mockJob->shouldReceive('hasFailed')->andReturn(false);

    event(new JobAttempted('default', $mockJob, false));

    expect($called)->toBeTrue();
});
