<?php

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Exceptions;
use Keepsuit\LaravelOpenTelemetry\WorkerMode\Detectors\ReverbWorkerModeDetector;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function () {
    $this->originalServer = $_SERVER;

    $this->timer = Mockery::mock(TimerInterface::class);
    $this->loop = Mockery::mock(LoopInterface::class);
    $this->loop->shouldReceive('stop');
    $this->loop->shouldReceive('addPeriodicTimer')->andReturnUsing(function (float $interval, Closure $callback) {
        $this->interval = $interval;
        $this->tick = $callback;

        return $this->timer;
    });

    // The real global loop runs on shutdown if never started, which would hang the test suite.
    Loop::set($this->loop);
});

afterEach(function () {
    $_SERVER = $this->originalServer;
    Loop::stop();
});

test('detects when reverb:start is running', function () {
    $_SERVER['APP_RUNNING_IN_CONSOLE'] = '1';
    $_SERVER['argv'] = ['artisan', 'reverb:start'];

    expect((new ReverbWorkerModeDetector)->detect())->toBeTrue();
});

test('does not detect when reverb:start is not running', function () {
    expect((new ReverbWorkerModeDetector)->detect())->toBeFalse();
});

test('registers a periodic timer every second', function () {
    $called = false;
    (new ReverbWorkerModeDetector)->onIterationEnded(function () use (&$called) {
        $called = true;
    });

    ($this->tick)();

    expect($this->interval)->toEqual(1)
        ->and($called)->toBeTrue();
});

test('reports exceptions thrown by the iteration callback', function () {
    Exceptions::fake();

    (new ReverbWorkerModeDetector)->onIterationEnded(fn () => throw new RuntimeException('export failed'));

    ($this->tick)();

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'export failed');
});

test('cancels the timer when reverb:start finishes', function () {
    $this->loop->shouldReceive('cancelTimer')->once()->with($this->timer);

    (new ReverbWorkerModeDetector)->onIterationEnded(fn () => null);

    event(new CommandFinished('other:command', new ArrayInput([]), new NullOutput, 0));
    event(new CommandFinished('reverb:start', new ArrayInput([]), new NullOutput, 1));
});
