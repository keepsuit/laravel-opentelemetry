<?php

namespace Keepsuit\LaravelOpenTelemetry\Facades;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Keepsuit\LaravelOpenTelemetry\Tracer tracer()
 * @method static \Keepsuit\LaravelOpenTelemetry\Meter meter()
 * @method static \Keepsuit\LaravelOpenTelemetry\Logger logger()
 * @method static void user(\Closure $resolver)
 * @method static array<non-empty-string, bool|int|float|string|array|null> collectUserContext(Authenticatable $user)
 */
class OpenTelemetry extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Keepsuit\LaravelOpenTelemetry\OpenTelemetry::class;
    }
}
