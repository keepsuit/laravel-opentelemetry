<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Event;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Trace\SpanKind;

class RedisInstrumentation implements Instrumentation
{
    use SpanTimeAdapter;

    public function register(array $options): void
    {
        Event::listen(CommandExecuted::class, [$this, 'recordCommand']);

        if (app()->resolved('redis')) {
            $this->registerRedisEvents(app()->make('redis'));
        } else {
            app()->afterResolving('redis', fn ($redis) => $this->registerRedisEvents($redis));
        }
    }

    public function recordCommand(CommandExecuted $event): void
    {
        $traceName = sprintf('redis %s %s', $event->connection->getName(), $event->command);

        $span = Tracer::build($traceName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setStartTimestamp($this->getEventStartTimestampNs($event->time))
            ->startSpan();

        if ($span->isRecording()) {
            $span->setAttribute('db.system', 'redis')
                ->setAttribute('db.statement', $this->formatCommand($event->command, $event->parameters))
                ->setAttribute('net.peer.name', $event->connection->client()->getHost());
        }

        $span->end();
    }

    /**
     * Format the given Redis command.
     */
    private function formatCommand(string $command, array $parameters): string
    {
        $parameters = collect($parameters)->map(function ($parameter) {
            if (is_array($parameter)) {
                return collect($parameter)->map(function ($value, $key) {
                    if (is_array($value)) {
                        return json_encode($value);
                    }

                    return is_int($key) ? $value : sprintf('%s %s', $key, $value);
                })->implode(' ');
            }

            return $parameter;
        })->implode(' ');

        return sprintf('%s %s', $command, $parameters);
    }

    private function registerRedisEvents(mixed $redis): void
    {
        if ($redis instanceof RedisManager) {
            foreach ((array) $redis->connections() as $connection) {
                $connection->setEventDispatcher(app('events'));
            }

            $redis->enableEvents();
        }
    }
}
