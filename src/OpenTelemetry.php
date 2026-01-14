<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

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

    /**
     * Force flush all OpenTelemetry signals (traces, metrics, logs)
     *
     * This method explicitly flushes all pending telemetry data.
     * Useful in long-running processes to ensure data is exported without waiting for process shutdown.
     */
    public function flush(): void
    {
        try {
            /** @var TracerProviderInterface $tracerProvider */
            $tracerProvider = $this->app->make(TracerProviderInterface::class);
            $tracerProvider->forceFlush();
        } catch (\Throwable) {
            // Silently ignore if tracer provider is not available
        }

        try {
            /** @var MeterProviderInterface $meterProvider */
            $meterProvider = $this->app->make(MeterProviderInterface::class);
            $meterProvider->forceFlush();
        } catch (\Throwable) {
            // Silently ignore if meter provider is not available
        }

        try {
            /** @var LoggerProviderInterface $loggerProvider */
            $loggerProvider = $this->app->make(LoggerProviderInterface::class);
            $loggerProvider->forceFlush();
        } catch (\Throwable) {
            // Silently ignore if logger provider is not available
        }
    }

    /**
     * Shutdown all OpenTelemetry providers
     *
     * This performs a graceful shutdown of all OpenTelemetry providers,
     * flushing any pending data and cleaning up resources.
     * Should typically only be called when the application is shutting down.
     */
    public function shutdown(): void
    {
        try {
            /** @var TracerProviderInterface $tracerProvider */
            $tracerProvider = $this->app->make(TracerProviderInterface::class);
            $tracerProvider->shutdown();
        } catch (\Throwable) {
            // Silently ignore if tracer provider is not available
        }

        try {
            /** @var MeterProviderInterface $meterProvider */
            $meterProvider = $this->app->make(MeterProviderInterface::class);
            $meterProvider->shutdown();
        } catch (\Throwable) {
            // Silently ignore if meter provider is not available
        }

        try {
            /** @var LoggerProviderInterface $loggerProvider */
            $loggerProvider = $this->app->make(LoggerProviderInterface::class);
            $loggerProvider->shutdown();
        } catch (\Throwable) {
            // Silently ignore if logger provider is not available
        }
    }

    /**
     * Get the current worker mode detector
     */
    public function getWorkerModeDetector(): Support\WorkerMode\WorkerModeDetector
    {
        return $this->app->make(Support\WorkerMode\WorkerModeDetector::class);
    }

    /**
     * Check if running in a worker mode (Octane, Horizon, Queue, etc.)
     */
    public function isRunningInWorkerMode(): bool
    {
        return $this->getWorkerModeDetector()->isWorkerMode();
    }

    /**
     * Get the detected worker mode name
     */
    public function getDetectedMode(): string
    {
        return $this->getWorkerModeDetector()->getDetectedMode();
    }
}
