<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;

class OpenTelemetry
{
    public function __construct(
        protected Application $app
    ) {}

    public function tracer(): Tracer
    {
        return $this->app->make(Tracer::class);
    }

    public function meter(): Meter
    {
        return $this->app->make(Meter::class);
    }

    public function logger(): Logger
    {
        return $this->app->make(Logger::class);
    }

    /**
     * Resolve user context attributes.
     *
     * @param  Closure(\Illuminate\Contracts\Auth\Authenticatable): array<non-empty-string, bool|int|float|string|array|null>  $resolver
     */
    public function user(Closure $resolver): void
    {
        $userContext = $this->app->make(Support\UserContextResolver::class);

        $userContext->setResolver($resolver);
    }

    /**
     * @return array<non-empty-string, bool|int|float|string|array|null>
     */
    public function collectUserContext(Authenticatable $user): array
    {
        $userContext = $this->app->make(Support\UserContextResolver::class);

        return $userContext->collect($user);
    }
}
