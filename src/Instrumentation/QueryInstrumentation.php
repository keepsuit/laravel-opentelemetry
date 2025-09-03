<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;

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

        $operationName = Str::of($event->sql)
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

        $span = Tracer::newSpan(sprintf('sql %s', $operationName))
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setStartTimestamp($this->getEventStartTimestampNs($event->time))
            ->setAttribute(DbAttributes::DB_SYSTEM_NAME, $event->connection->getDriverName())
            ->setAttribute(DbAttributes::DB_NAMESPACE, $event->connection->getDatabaseName())
            ->setAttribute(DbAttributes::DB_OPERATION_NAME, $operationName)
            ->setAttribute(DbAttributes::DB_QUERY_TEXT, $event->sql)
            ->setAttribute(ServerAttributes::SERVER_ADDRESS, $event->connection->getConfig('host'))
            ->setAttribute(ServerAttributes::SERVER_PORT, $event->connection->getConfig('port'))
            ->start();

        $span->end();
    }
}
