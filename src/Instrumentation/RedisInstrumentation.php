<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\RedisManager;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Support\InstrumentationUtilities;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\TraceAttributes;

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

        $traceName = sprintf('redis %s %s', $event->connection->getName(), $event->command);

        $span = Tracer::newSpan($traceName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setStartTimestamp($this->getEventStartTimestampNs($event->time))
            ->start();

        if ($span->isRecording()) {
            $span->setAttribute(TraceAttributes::DB_SYSTEM_NAME, 'redis')
                ->setAttribute(TraceAttributes::DB_QUERY_TEXT, $this->formatCommand($event->command, $event->parameters))
                ->setAttribute(TraceAttributes::SERVER_ADDRESS, $this->resolveRedisAddress($event->connection->client()));
        }

        $span->end();
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
