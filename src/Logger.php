<?php

namespace Keepsuit\LaravelOpenTelemetry;

use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Logs\Map\Psr3;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use Psr\Log\LogLevel;

class Logger
{
    public function __construct(
        protected LoggerInterface $logger
    ) {
    }

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
        $logRecord = (new LogRecord($message))
            ->setTimestamp(ClockFactory::getDefault()->now())
            ->setSeverityNumber(Psr3::severityNumber($level))
            ->setSeverityText($level);

        foreach ($context as $key => $value) {
            $logRecord->setAttribute($key, $value);
        }

        $this->logger->emit($logRecord);
    }
}
