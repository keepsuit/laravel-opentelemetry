<?php

namespace Keepsuit\LaravelOpentelemetry;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Keepsuit\LaravelOpentelemetry\LaravelOpentelemetry
 */
class LaravelOpentelemetryFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'laravel-opentelemetry';
    }
}
