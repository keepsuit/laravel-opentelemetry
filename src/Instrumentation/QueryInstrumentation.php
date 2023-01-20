<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Database\Events\QueryExecuted;
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
        $traceName = sprintf('%s %s', $event->connection->getDriverName(), $event->connection->getDatabaseName());

        $span = Tracer::build($traceName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setStartTimestamp($this->getEventStartTimestampNs($event->time))
            ->startSpan();

        if ($span->isRecording()) {
            $span->setAttribute(TraceAttributes::DB_SYSTEM, $event->connection->getDriverName())
                ->setAttribute(TraceAttributes::DB_NAME, $event->connection->getDatabaseName())
                ->setAttribute(TraceAttributes::DB_STATEMENT, $this->replaceBindings($event))
                ->setAttribute(TraceAttributes::NET_PEER_NAME, $event->connection->getConfig('host'))
                ->setAttribute(TraceAttributes::NET_PEER_PORT, $event->connection->getConfig('port'));
        }

        $span->end();
    }

    /**
     * Format the given bindings to strings.
     *
     * @param  \Illuminate\Database\Events\QueryExecuted  $event
     * @return array
     */
    protected function formatBindings($event)
    {
        return $event->connection->prepareBindings($event->bindings);
    }

    /**
     * Replace the placeholders with the actual bindings.
     *
     * @param  \Illuminate\Database\Events\QueryExecuted  $event
     * @return string
     */
    public function replaceBindings($event)
    {
        $sql = $event->sql;

        foreach ($this->formatBindings($event) as $key => $binding) {
            $regex = is_numeric($key)
                ? "/\?(?=(?:[^'\\\']*'[^'\\\']*')*[^'\\\']*$)/"
                : "/:{$key}(?=(?:[^'\\\']*'[^'\\\']*')*[^'\\\']*$)/";

            if ($binding === null) {
                $binding = 'null';
            } elseif (! is_int($binding) && ! is_float($binding)) {
                $binding = $this->quoteStringBinding($event, $binding);
            }

            $sql = preg_replace($regex, $binding, $sql, 1);
        }

        return $sql;
    }

    /**
     * Add quotes to string bindings.
     *
     * @param  \Illuminate\Database\Events\QueryExecuted  $event
     * @param  string  $binding
     * @return string
     */
    protected function quoteStringBinding($event, $binding)
    {
        try {
            return $event->connection->getPdo()->quote($binding);
        } catch (\PDOException $e) {
            throw_if('IM001' !== $e->getCode(), $e);
        }

        // Fallback when PDO::quote function is missing...
        $binding = \strtr($binding, [
            chr(26) => '\\Z',
            chr(8) => '\\b',
            '"' => '\"',
            "'" => "\'",
            '\\' => '\\\\',
        ]);

        return "'".$binding."'";
    }
}
