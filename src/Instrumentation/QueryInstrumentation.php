<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Keepsuit\LaravelOpenTelemetry\Facades\Meter;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Metrics\DbMetrics;

class QueryInstrumentation implements Instrumentation
{
    use SpanTimeAdapter;

    public function register(array $options): void
    {
        app('events')->listen(QueryExecuted::class, [$this, 'recordQuery']);
    }

    public function recordQuery(QueryExecuted $event): void
    {
        if (! Tracer::traceStarted()) {
            return;
        }

        $operationName = (string) Str::of($event->sql)
            ->before(' ')
            ->upper()
            ->when(
                value: fn (Stringable $operationName) => in_array($operationName, ['SELECT', 'INSERT', 'UPDATE', 'DELETE']),
                callback: fn (Stringable $operationName) => $operationName->toString(),
                default: fn () => ''
            );

        if ($operationName === '') {
            return;
        }

        $this->recordOperationDurationMetric($event, $operationName);
        $this->recordTraceSpan($event, $operationName);
    }

    protected function recordTraceSpan(QueryExecuted $event, string $operationName): void
    {
        $attributes = $this->sharedTraceMetricAttributes($event, $operationName);

        $span = Tracer::newSpan($operationName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setStartTimestamp($this->getEventStartTimestampNs($event->time))
            ->setAttributes($attributes)
            ->start();

        $span->end();
    }

    protected function recordOperationDurationMetric(QueryExecuted $event, string $operationName): void
    {
        $duration = Clock::getDefault()->now() - $this->getEventStartTimestampNs($event->time);

        $attributes = $this->sharedTraceMetricAttributes($event, $operationName);

        // @see https://opentelemetry.io/docs/specs/semconv/db/database-metrics/#metric-dbclientoperationduration
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
    protected function sharedTraceMetricAttributes(QueryExecuted $event, string $operationName): array
    {
        return [
            DbAttributes::DB_SYSTEM_NAME => $event->connection->getDriverName(),
            DbAttributes::DB_NAMESPACE => $event->connection->getDatabaseName(),
            DbAttributes::DB_OPERATION_NAME => $operationName,
            DbAttributes::DB_QUERY_TEXT => $event->sql,
            ServerAttributes::SERVER_ADDRESS => $event->connection->getConfig('host'),
            ServerAttributes::SERVER_PORT => $event->connection->getConfig('port'),
        ];
    }
}
