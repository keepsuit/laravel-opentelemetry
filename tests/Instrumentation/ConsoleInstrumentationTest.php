<?php

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\ConsoleInstrumentation;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    registerInstrumentation(ConsoleInstrumentation::class);
});

test('trace console command', function () {
    simulateTestConsoleCommand();

    $spans = getRecordedSpans();

    expect($spans)->toHaveCount(1);

    $consoleSpan = $spans->first();

    expect($consoleSpan)
        ->toBeInstanceOf(\OpenTelemetry\SDK\Trace\ImmutableSpan::class)
        ->getName()->toBe('test:command')
        ->getStatus()->getCode()->toBe(\OpenTelemetry\API\Trace\StatusCode::STATUS_OK);
});

test('trace console command with failing status', function () {
    simulateTestConsoleCommand(exitCode: 1);

    $spans = getRecordedSpans();

    expect($spans)->toHaveCount(1);

    $consoleSpan = $spans->first();

    expect($consoleSpan)
        ->toBeInstanceOf(\OpenTelemetry\SDK\Trace\ImmutableSpan::class)
        ->getName()->toBe('test:command')
        ->getStatus()->getCode()->toBe(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR);
});

function simulateTestConsoleCommand(int $exitCode = 0): void
{
    app('events')->dispatch(new CommandStarting(
        $command = 'test:command',
        $input = new ArgvInput(['artisan', 'test:command', '--foo=bar']),
        $output = new BufferedOutput
    ));

    app('events')->dispatch(new CommandFinished(
        $command,
        $input,
        $output,
        exitCode: $exitCode
    ));
}
