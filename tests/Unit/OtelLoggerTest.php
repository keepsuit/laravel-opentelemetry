<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Keepsuit\LaravelOpenTelemetry\Facades\Logger;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use Psr\Log\LogLevel;
use Spatie\TestTime\TestTime;

afterEach(function () {
    Context::flush();
});

it('can log a message', function (string $level) {
    TestTime::freeze();

    Logger::$level('test', ['foo' => 'bar']);

    $logs = getRecordedLogs();

    expect($logs)->toHaveCount(1);

    expect($logs->first())
        ->toBeInstanceOf(ReadableLogRecord::class)
        ->getBody()->toBe('test')
        ->getSeverityNumber()->toBe(Severity::fromPsr3($level)->value)
        ->getSeverityText()->toBe($level)
        ->getTimestamp()->toBe(Clock::getDefault()->now())
        ->getAttributes()->toArray()->toBe(['foo' => 'bar']);
})->with([
    LogLevel::EMERGENCY,
    LogLevel::ALERT,
    LogLevel::CRITICAL,
    LogLevel::ERROR,
    LogLevel::WARNING,
    LogLevel::NOTICE,
    LogLevel::INFO,
    LogLevel::DEBUG,
]);

it('can log a message through laravel Log facade', function (string $level) {
    TestTime::freeze();

    Log::$level('test', ['foo' => 'bar']);

    $logs = getRecordedLogs();

    expect($logs)->toHaveCount(1);

    expect($logs->first())
        ->toBeInstanceOf(ReadableLogRecord::class)
        ->getBody()->toBe('test')
        ->getSeverityNumber()->toBe(Severity::fromPsr3($level)->value)
        ->getSeverityText()->toBe($level)
        ->getTimestamp()->toBe(Clock::getDefault()->now())
        ->getAttributes()->toArray()->toBe(['foo' => 'bar']);
})->with([
    LogLevel::EMERGENCY,
    LogLevel::ALERT,
    LogLevel::CRITICAL,
    LogLevel::ERROR,
    LogLevel::WARNING,
    LogLevel::NOTICE,
    LogLevel::INFO,
    LogLevel::DEBUG,
]);

it('exports laravel context data as log attributes', function () {
    Context::add('foo', 'bar');

    Log::info('test');

    expect(getRecordedLogs()->first())
        ->getAttributes()->toArray()->toBe(['foo' => 'bar']);
});

it('prefers explicit log context over laravel context data with the same key', function () {
    Context::add(['foo' => 'from-context', 'request_id' => 'abc']);

    Log::info('test', ['foo' => 'explicit']);

    expect(getRecordedLogs()->first())
        ->getAttributes()->toArray()->toEqual(['request_id' => 'abc', 'foo' => 'explicit']);
});

it('does not export hidden laravel context data', function () {
    Context::add('visible', 'yes');
    Context::addHidden('secret', 'no');

    Log::info('test');

    expect(getRecordedLogs()->first())
        ->getAttributes()->toArray()->toBe(['visible' => 'yes']);
});

it('exports shared log context alongside explicit log context', function () {
    Log::shareContext(['shared' => 'value']);

    Log::info('test', ['foo' => 'bar']);

    expect(getRecordedLogs()->first())
        ->getAttributes()->toArray()->toEqual(['shared' => 'value', 'foo' => 'bar']);
});

it('strips the trace id field from exported log attributes', function () {
    Log::shareContext(['trace_id' => 'shared-trace-id']);
    Context::add('trace_id', 'context-trace-id');

    Log::info('test', ['foo' => 'bar']);

    expect(getRecordedLogs()->first())
        ->getAttributes()->toArray()->toBe(['foo' => 'bar']);
});
