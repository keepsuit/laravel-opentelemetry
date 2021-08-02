<?php

namespace Keepsuit\LaravelOpenTelemetry\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Keepsuit\LaravelOpenTelemetry\Tracer start(string $name, ?\Closure $onStart = null)
 * @method static \Keepsuit\LaravelOpenTelemetry\Tracer stop(string $name, ?\Closure $onStop = null)
 * @method static mixed measure(string $name, \Closure $callback = null)
 */
class Tracer extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Keepsuit\LaravelOpenTelemetry\Tracer::class;
    }
}
