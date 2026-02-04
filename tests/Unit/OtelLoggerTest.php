<?php

use Illuminate\Support\Facades\Log;
use Keepsuit\LaravelOpenTelemetry\Facades\Logger;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Logs\Severity;
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
