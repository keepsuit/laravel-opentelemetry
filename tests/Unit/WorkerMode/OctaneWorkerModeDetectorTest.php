<?php

use Keepsuit\LaravelOpenTelemetry\WorkerMode\Detectors\OctaneWorkerModeDetector;
use Laravel\Octane\Events\RequestTerminated;
use Symfony\Component\HttpFoundation\Response;

test('detects octane when from env variable', function (?string $value, bool $expected) {
    if ($value !== null) {
        $_SERVER['LARAVEL_OCTANE'] = $value;
    }

    $detector = new OctaneWorkerModeDetector;

    expect($detector->detect())->toBe($expected);

    unset($_SERVER['LARAVEL_OCTANE']);
})->with([
    ['1', true],
    ['true', true],
    ['0', false],
    ['false', false],
    [null, false],
]);

test('registers callback for RequestTerminated event', function () {
    $detector = new OctaneWorkerModeDetector;
    $called = false;

    $detector->onIterationEnded(function () use (&$called) {
        $called = true;
    });

    $request = new \Illuminate\Http\Request;
    $response = new Response;
    event(new RequestTerminated(app(), app(), $request, $response));

    expect($called)->toBeTrue();
});
