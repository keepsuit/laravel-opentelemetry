<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\RedisManager;
use Keepsuit\LaravelOpenTelemetry\Facades\Meter;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\Support\InstrumentationUtilities;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Metrics\DbMetrics;

class RedisInstrumentation implements Instrumentation
{
    use InstrumentationUtilities;
    use SpanTimeAdapter;

    public function register(array $options): void
    {
        app('events')->listen(CommandExecuted::class, [$this, 'recordCommand']);

        $this->callAfterResolving('redis', $this->registerRedisEvents(...));
    }

    public function recordCommand(CommandExecuted $event): void
    {
        if (! Tracer::traceStarted()) {
            return;
        }

        $operationName = strtoupper($event->command);

        $this->recordOperationDurationMetric($event, $operationName);
        $this->recordTraceSpan($event, $operationName);
    }

    protected function recordTraceSpan(CommandExecuted $event, string $operationName): void
    {
        $span = Tracer::newSpan($operationName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setStartTimestamp($this->getEventStartTimestampNs($event->time))
            ->start();

        $span->setAttributes($this->sharedTraceMetricAttributes($event, $operationName));

        $span->end();
    }

    protected function recordOperationDurationMetric(CommandExecuted $event, string $operationName): void
    {
        $duration = Clock::getDefault()->now() - $this->getEventStartTimestampNs($event->time);

        $attributes = $this->sharedTraceMetricAttributes($event, $operationName);

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
    protected function sharedTraceMetricAttributes(CommandExecuted $event, string $operationName): array
    {
        return [
            DbAttributes::DB_SYSTEM_NAME => 'redis',
            DbAttributes::DB_OPERATION_NAME => $operationName,
            DbAttributes::DB_NAMESPACE => $this->resolveDbIndex($event->connectionName),
            DbAttributes::DB_QUERY_TEXT => $this->formatCommand($event->command, $event->parameters),
            ServerAttributes::SERVER_ADDRESS => $this->resolveRedisAddress($event->connection->client()),
        ];
    }

    protected function resolveRedisAddress(mixed $client): ?string
    {
        if ($client instanceof \Redis) {
            return $client->getHost() ?: null;
        }

        if ($client instanceof \Predis\Client) {
            $connection = $client->getConnection();

            return $connection instanceof \Predis\Connection\NodeConnectionInterface
                ? ($connection->getParameters()->host ?? null)
                : null;
        }

        return null;
    }

    /**
     * Format the given Redis command.
     */
    protected function formatCommand(string $command, array $parameters): string
    {
        $parameters = collect($parameters)->map(function ($parameter) {
            if (is_array($parameter)) {
                return collect($parameter)->map(function ($value, $key) {
                    if (is_array($value)) {
                        return \Safe\json_encode($value);
                    }

                    return is_int($key) ? $value : sprintf('%s %s', $key, $value);
                })->implode(' ');
            }

            return $parameter;
        })->implode(' ');

        return sprintf('%s %s', $command, $parameters);
    }

    protected function resolveDbIndex(string $connectionName): ?string
    {
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
