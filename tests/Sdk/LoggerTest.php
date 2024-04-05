<?php

use Illuminate\Support\Facades\Log;
use Keepsuit\LaravelOpenTelemetry\Facades\Logger;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Logs\Map\Psr3;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use Psr\Log\LogLevel;
use Spatie\TestTime\TestTime;

it('can log a message', function (string $level) {
    TestTime::freeze();

    Logger::$level('test', ['foo' => 'bar']);

    $logs = getRecordedLogs();

    expect($logs)->toHaveCount(1);

    expect($logs->first())
        ->toBeInstanceOf(ReadableLogRecord::class)
        ->getBody()->toBe('test')
        ->getSeverityNumber()->toBe(Psr3::severityNumber($level))
        ->getSeverityText()->toBe($level)
        ->getTimestamp()->toBe(ClockFactory::getDefault()->now())
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
        ->getSeverityNumber()->toBe(Psr3::severityNumber($level))
        ->getSeverityText()->toBe($level)
        ->getTimestamp()->toBe(ClockFactory::getDefault()->now())
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

test('trace id is injected without log context sharing', function () {
    $span = Tracer::newSpan('test')->start();
    $scope = $span->activate();

    $traceId = Tracer::traceId();
    expect(\OpenTelemetry\API\Trace\SpanContextValidator::isValidTraceId($traceId))->toBeTrue();

    Logger::info('test');

    $scope->detach();
    $span->end();

    $log = getRecordedLogs()->first();

    expect($log)
        ->getBody()->toBe('test')
        ->getAttributes()->toArray()->toBe([
            'traceId' => $traceId,
        ]);
});

test('trace id is not injected when tracer scope is not valid', function () {
    $span = Tracer::newSpan('test')->start();

    $traceId = Tracer::traceId();
    expect($traceId)->toBeNull();

    Logger::info('test');

    $span->end();

    $log = getRecordedLogs()->first();

    expect($log)
        ->getBody()->toBe('test')
        ->getAttributes()->toArray()->toBe([]);
});
