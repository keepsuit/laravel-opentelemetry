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

it('drops records below LOG_LEVEL on the injected otlp channel', function () {
    putenv('LOG_LEVEL=warning');
    $this->refreshApplication();

    Log::info('dropped');
    Log::warning('kept');

    expect(config('logging.channels.otlp.level'))->toBe('warning');
    expect(getRecordedLogs()->map->getBody()->all())->toBe(['kept']);
})->after(fn () => putenv('LOG_LEVEL'));
