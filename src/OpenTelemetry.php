<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use OpenTelemetry\API\Globals;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

class OpenTelemetry
{
    public function tracer(): Tracer
    {
        return Container::getInstance()->make(Tracer::class);
    }

    public function meter(): Meter
    {
        return Container::getInstance()->make(Meter::class);
    }

    public function logger(): Logger
    {
        return Container::getInstance()->make(Logger::class);
    }

    /**
     * Resolve user context attributes.
     *
     * @param  Closure(\Illuminate\Contracts\Auth\Authenticatable): array<non-empty-string, bool|int|float|string|array|null>  $resolver
     */
    public function user(Closure $resolver): void
    {
        $userContext = Container::getInstance()->make(Support\UserContextResolver::class);

        $userContext->setResolver($resolver);
    }

    /**
     * @return array<non-empty-string, bool|int|float|string|array|null>
     */
    public function collectUserContext(Authenticatable $user): array
    {
        $userContext = Container::getInstance()->make(Support\UserContextResolver::class);

        return $userContext->collect($user);
    }

    /**
     * Force flush all OpenTelemetry signals (traces, metrics, logs)
     *
     * This method explicitly flushes all pending telemetry data.
     * Useful in long-running processes to ensure data is exported without waiting for process shutdown.
     */
    public function flush(): void
    {
        $tracerProvider = Globals::tracerProvider();
        if ($tracerProvider instanceof TracerProviderInterface) {
            $tracerProvider->forceFlush();
        }

        $meterProvider = Globals::meterProvider();
        if ($meterProvider instanceof MeterProviderInterface) {
            $meterProvider->forceFlush();
        }

        $loggerProvider = Globals::loggerProvider();
        if ($loggerProvider instanceof LoggerProviderInterface) {
            $loggerProvider->forceFlush();
        }
    }
}
