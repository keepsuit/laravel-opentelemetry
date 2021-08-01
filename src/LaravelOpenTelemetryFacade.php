<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Keepsuit\LaravelOpenTelemetry\LaravelOpenTelemetry
 */
class LaravelOpenTelemetryFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'laravel-opentelemetry';
    }
}
