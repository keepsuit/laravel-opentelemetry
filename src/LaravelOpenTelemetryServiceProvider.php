<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use OpenTelemetry\Contrib\Jaeger\Exporter as JaegerExporter;
use OpenTelemetry\Contrib\Zipkin\Exporter as ZipkinExporter;
use OpenTelemetry\Sdk\Trace\Clock;
use OpenTelemetry\Sdk\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\Sdk\Trace\TracerProvider;
use OpenTelemetry\Trace\Tracer;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelOpenTelemetryServiceProvider extends PackageServiceProvider
{
    public function register()
    {
        parent::register();

        $this->initTracer();
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-opentelemetry')
            ->hasConfigFile();
    }

    protected function initTracer(): void
    {
        $this->app->singleton(Tracer::class, function () {
            $exporter = match (config('opentelemetry.exporter')) {
                'jaeger' => new JaegerExporter(
                    config('opentelemetry.service_name'),
                    config('opentelemetry.exporters.jaeger.endpoint'),
                    Psr18ClientDiscovery::find(),
                    Psr17FactoryDiscovery::findRequestFactory(),
                    Psr17FactoryDiscovery::findStreamFactory()
                ),
                'zipkin' => new ZipkinExporter(
                    config('opentelemetry.service_name'),
                    config('opentelemetry.exporters.zipkin.endpoint'),
                    Psr18ClientDiscovery::find(),
                    Psr17FactoryDiscovery::findRequestFactory(),
                    Psr17FactoryDiscovery::findStreamFactory()
                ),
                default => null
            };

            return (new TracerProvider())
                ->addSpanProcessor(new BatchSpanProcessor($exporter, Clock::get()))
                ->getTracer('io.opentelemetry.contrib.php');
        });

        $this->app->terminating(function () {
            if (app()->resolved(Tracer::class)) {
                app(Tracer::class)->getTracerProvider()->shutdown();
            }
        });
    }
}
