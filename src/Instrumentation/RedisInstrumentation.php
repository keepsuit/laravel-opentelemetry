<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\Events\CommandFailed;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Keepsuit\LaravelOpenTelemetry\Facades\Meter;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\Support\InstrumentationUtilities;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Metrics\DbMetrics;
use Predis\Client;
use Predis\Connection\NodeConnectionInterface;

class RedisInstrumentation implements Instrumentation
{
    use InstrumentationUtilities;
    use SpanTimeAdapter;

    public function register(array $options): void
    {
        app('events')->listen(CommandExecuted::class, [$this, 'recordCommand']);

        // CommandFailed was added during Laravel 12.x
        if (class_exists(CommandFailed::class)) {
            app('events')->listen(CommandFailed::class, [$this, 'recordCommand']);
        }

        $this->callAfterResolving('redis', $this->registerRedisEvents(...));
    }

    public function recordCommand(CommandExecuted|CommandFailed $event): void
    {
        if (! Tracer::traceStarted()) {
            return;
        }

        $operationName = strtoupper($event->command);
        // CommandFailed carries no duration: failed commands get a zero-length span and no metric, so latencies stay accurate.
        $startTimestamp = $this->getEventStartTimestampNs($event instanceof CommandExecuted ? $event->time : null);
        $exception = $event instanceof CommandFailed ? $event->exception : null;

        $sharedAttributes = $this->sharedTraceMetricAttributes($event, $operationName);

        if ($exception !== null) {
            $sharedAttributes[ErrorAttributes::ERROR_TYPE] = $exception::class;
        }

        $this->recordTraceSpan($startTimestamp, $operationName, $sharedAttributes, $exception);

        if ($exception === null) {
            $this->recordOperationDurationMetric($startTimestamp, Arr::except($sharedAttributes, DbAttributes::DB_QUERY_TEXT));
        }
    }

    /**
     * @param  array<non-empty-string, bool|int|float|string|array|null>  $attributes
     */
    protected function recordTraceSpan(int $startTimestamp, string $operationName, array $attributes, ?\Throwable $exception): void
    {
        $span = Tracer::newSpan($operationName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setStartTimestamp($startTimestamp)
            ->start();

        $span->setAttributes($attributes);

        if ($exception !== null) {
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }

        $span->end();
    }

    /**
     * @param  array<non-empty-string, bool|int|float|string|array|null>  $attributes
     */
    protected function recordOperationDurationMetric(int $startTimestamp, array $attributes): void
    {
        $duration = (Clock::getDefault()->now() - $startTimestamp) / ClockInterface::NANOS_PER_SECOND;

        Meter::histogram(
            name: DbMetrics::DB_CLIENT_OPERATION_DURATION,
            unit: 's',
            description: 'Duration of database client operations.',
            advisory: [
                'ExplicitBucketBoundaries' => [0.001, 0.005, 0.01, 0.05, 0.1, 0.5, 1.0, 5.0, 10.0],
            ])
            ->record($duration, $attributes);
    }

    /**
     * @return array<non-empty-string, bool|int|float|string|array|null>
     */
    protected function sharedTraceMetricAttributes(CommandExecuted|CommandFailed $event, string $operationName): array
    {
        [$address, $port] = $this->resolveRedisServer($event->connection->client());

        $attributes = [
            DbAttributes::DB_SYSTEM_NAME => 'redis',
            DbAttributes::DB_OPERATION_NAME => $operationName,
            DbAttributes::DB_NAMESPACE => $this->resolveDbIndex($event->connectionName),
            DbAttributes::DB_QUERY_TEXT => Str::limit($this->formatCommand($operationName, $event->parameters), 500),
            ServerAttributes::SERVER_ADDRESS => $address,
        ];

        if ($address !== null && $port !== null && $port !== 6379) {
            $attributes[ServerAttributes::SERVER_PORT] = $port;
        }

        return $attributes;
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    protected function resolveRedisServer(mixed $client): array
    {
        if ($client instanceof \Redis) {
            return [$client->getHost() ?: null, $client->getPort() ?: null];
        }

        if ($client instanceof Client) {
            $connection = $client->getConnection();

            if ($connection instanceof NodeConnectionInterface) {
                $parameters = $connection->getParameters();

                return [$parameters->host ?? null, isset($parameters->port) ? (int) $parameters->port : null];
            }
        }

        return [null, null];
    }

    /**
     * Format the given Redis command, replacing every argument (keys included) with a placeholder.
     */
    protected function formatCommand(string $command, array $parameters): string
    {
        $placeholders = collect($parameters)->flatMap(function ($parameter) {
            if (is_array($parameter)) {
                return collect($parameter)->flatMap(fn ($value, $key) => match (true) {
                    ! is_int($key) => ['?', '?'],
                    $value === null => [],
                    default => ['?'],
                });
            }

            return $parameter === null ? [] : ['?'];
        });

        return $placeholders->prepend($command)->implode(' ');
    }

    protected function resolveDbIndex(?string $connectionName): ?string
    {
        if ($connectionName === null) {
            return null;
        }

        return config(sprintf('database.redis.%s.database', $connectionName));
    }

    protected function registerRedisEvents(mixed $redis): void
    {
        if ($redis instanceof RedisManager) {
            foreach ((array) $redis->connections() as $connection) {
                $connection->setEventDispatcher(app('events'));
            }

            $redis->enableEvents();
        }
    }
}
