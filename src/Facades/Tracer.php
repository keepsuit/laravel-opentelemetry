<?php

namespace Keepsuit\LaravelOpenTelemetry\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Keepsuit\LaravelOpenTelemetry\Tracer start(string $name, ?\Closure $onStart = null)
 * @method static \Keepsuit\LaravelOpenTelemetry\Tracer stop(string $name, ?\Closure $onStop = null)
 * @method static mixed measure(string $name, \Closure $callback = null)
 * @method static \OpenTelemetry\Trace\Span activeSpan()
 * @method static array activeSpanB3Headers()
 * @method static \Keepsuit\LaravelOpenTelemetry\Tracer initFromB3Headers(array $headers)
 */
class Tracer extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Keepsuit\LaravelOpenTelemetry\Tracer::class;
    }
}
