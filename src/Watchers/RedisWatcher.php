<?php

namespace Keepsuit\LaravelOpenTelemetry\Watchers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\RedisManager;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\Sdk\Trace\Span;
use OpenTelemetry\Trace\SpanKind;

class RedisWatcher extends Watcher
{
    use SpanTimeAdapter;

    public function register(Application $app): void
    {
        $app['events']->listen(CommandExecuted::class, [$this, 'recordCommand']);

        if ($app->resolved('redis')) {
            $this->registerRedisEvents($app->make('redis'), $app);
        } else {
            $app->afterResolving('redis', fn ($redis) => $this->registerRedisEvents($redis, $app));
        }
    }

    public function recordCommand(CommandExecuted $event)
    {
        $traceName = sprintf('redis %s %s', $event->connection->getName(), $event->command);

        Tracer::start($traceName, spanKind: SpanKind::KIND_CLIENT);
        Tracer::stop($traceName, function (Span $span) use ($event) {
            $span->setAttribute('db.system', 'redis');
            $span->setAttribute('db.statement', $this->formatCommand($event->command, $event->parameters));
            $span->setAttribute('net.peer.name', $event->connection->client()->getHost());

            $this->setSpanTimeMs($span, $event->time);
        });
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

                    return is_int($key) ? $value : sprintf("%s %s", $key, $value);
                })->implode(' ');
            }

            return $parameter;
        })->implode(' ');

        return sprintf("%s %s", $command, $parameters);
    }

    private function registerRedisEvents(mixed $redis, Application $app): void
    {
        if ($redis instanceof RedisManager) {
            foreach ((array)$redis->connections() as $connection) {
                $connection->setEventDispatcher($app['events']);
            }

            $redis->enableEvents();
        }
    }
}
