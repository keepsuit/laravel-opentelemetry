<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\TraceAttributes;

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
            ->setAttribute(TraceAttributes::DB_SYSTEM_NAME, $event->connection->getDriverName())
            ->setAttribute(TraceAttributes::DB_NAMESPACE, $event->connection->getDatabaseName())
            ->setAttribute(TraceAttributes::DB_OPERATION_NAME, $operationName)
            ->setAttribute(TraceAttributes::DB_QUERY_TEXT, $event->sql)
            ->setAttribute(TraceAttributes::SERVER_ADDRESS, $event->connection->getConfig('host'))
            ->setAttribute(TraceAttributes::SERVER_PORT, $event->connection->getConfig('port'))
            ->start();

        $span->end();
    }
}
