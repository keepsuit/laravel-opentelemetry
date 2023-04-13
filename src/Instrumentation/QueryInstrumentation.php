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
        $operationName = Str::of($event->sql)
            ->before(' ')
            ->upper()
            ->when(
                value: fn (Stringable $operationName) => in_array($operationName, ['SELECT', 'INSERT', 'UPDATE', 'DELETE']),
                callback: fn (Stringable $operationName) => $operationName->toString(),
                default: fn () => ''
            );

        $span = Tracer::build(sprintf('sql %s', $operationName))
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setStartTimestamp($this->getEventStartTimestampNs($event->time))
            ->startSpan();

        $span->setAttributes([
            TraceAttributes::DB_SYSTEM => $event->connection->getDriverName(),
            TraceAttributes::DB_NAME => $event->connection->getDatabaseName(),
            TraceAttributes::DB_OPERATION => $operationName,
            TraceAttributes::DB_STATEMENT => $event->sql,
            TraceAttributes::DB_USER => $event->connection->getConfig('username'),
            TraceAttributes::NET_PEER_NAME => $event->connection->getConfig('host'),
            TraceAttributes::NET_PEER_PORT => $event->connection->getConfig('port'),
        ]);

        $span->end();
    }
}
