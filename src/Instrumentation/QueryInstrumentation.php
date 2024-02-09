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

        $span = Tracer::newSpan(sprintf('sql %s', $operationName))
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setStartTimestamp($this->getEventStartTimestampNs($event->time))
            ->setAttribute(TraceAttributes::DB_SYSTEM, $event->connection->getDriverName())
            ->setAttribute(TraceAttributes::DB_NAME, $event->connection->getDatabaseName())
            ->setAttribute(TraceAttributes::DB_OPERATION, $operationName)
            ->setAttribute(TraceAttributes::DB_STATEMENT, $event->sql)
            ->setAttribute(TraceAttributes::DB_USER, $event->connection->getConfig('username'))
            ->setAttribute(TraceAttributes::SERVER_ADDRESS, $event->connection->getConfig('host'))
            ->setAttribute(TraceAttributes::SERVER_PORT, $event->connection->getConfig('port'))
            ->start();

        $span->end();
    }
}
