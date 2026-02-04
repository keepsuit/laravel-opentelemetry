<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Keepsuit\LaravelOpenTelemetry\Facades\OpenTelemetry;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Logs\Severity;
use Psr\Log\LogLevel;

class Logger
{
    public function __construct(
        protected LoggerInterface $logger
    ) {}

    public function emergency(string $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * @phpstan-param  LogLevel::* $level
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if (config('opentelemetry.logs.inject_trace_id')) {
            unset($context[config('opentelemetry.logs.trace_id_field')]);
        }

        $logRecord = (new LogRecord($message))
            ->setTimestamp(Clock::getDefault()->now())
            ->setSeverityNumber(Severity::fromPsr3($level))
            ->setSeverityText($level);

        foreach ($context as $key => $value) {
            $logRecord->setAttribute($key, $value);
        }

        if (config('opentelemetry.user_context') === true && Auth::user() instanceof Authenticatable) {
            $logRecord->setAttributes(OpenTelemetry::collectUserContext(Auth::user()));
        }

        $this->logger->emit($logRecord);
    }
}
