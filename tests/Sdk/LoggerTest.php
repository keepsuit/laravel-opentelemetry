<?php

use Keepsuit\LaravelOpenTelemetry\Facades\Logger;
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
