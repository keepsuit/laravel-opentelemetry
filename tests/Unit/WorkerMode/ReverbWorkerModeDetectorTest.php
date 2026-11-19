<?php

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Exceptions;
use Keepsuit\LaravelOpenTelemetry\WorkerMode\Detectors\ReverbWorkerModeDetector;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function () {
    // The detector booted by the worker mode manager would add its own timer.
    app('events')->forget(CommandStarting::class);

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
    Loop::stop();
});

function startReverbCommand(string $command = 'reverb:start'): void
{
    event(new CommandStarting($command, new ArrayInput([]), new NullOutput));
}

test('detects console processes', function () {
    expect((new ReverbWorkerModeDetector)->detect())->toBeTrue();
});

test('does not detect outside the console', function () {
    invade(app())->isRunningInConsole = false;

    expect((new ReverbWorkerModeDetector)->detect())->toBeFalse();
});

test('does not register a timer for other commands', function () {
    $this->loop->shouldNotReceive('addPeriodicTimer');

    (new ReverbWorkerModeDetector)->onIterationEnded(fn () => null);

    startReverbCommand('queue:work');
});

test('registers a periodic timer every second', function () {
    $called = false;
    (new ReverbWorkerModeDetector)->onIterationEnded(function () use (&$called) {
        $called = true;
    });
    startReverbCommand();

    ($this->tick)();

    expect($this->interval)->toEqual(1)
        ->and($called)->toBeTrue();
});

test('reports exceptions thrown by the iteration callback', function () {
    Exceptions::fake();

    (new ReverbWorkerModeDetector)->onIterationEnded(fn () => throw new RuntimeException('export failed'));
    startReverbCommand();

    ($this->tick)();

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'export failed');
});

test('cancels the timer when reverb:start finishes', function () {
    $this->loop->shouldReceive('cancelTimer')->once()->with($this->timer);

    (new ReverbWorkerModeDetector)->onIterationEnded(fn () => null);
    startReverbCommand();

    event(new CommandFinished('other:command', new ArrayInput([]), new NullOutput, 0));
    event(new CommandFinished('reverb:start', new ArrayInput([]), new NullOutput, 1));
});
