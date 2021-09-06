<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Keepsuit\LaravelOpenTelemetry\Watchers\Watcher;
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
        $this->registerWatchers();
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
                $tracer = app(Tracer::class);

                if ($tracer instanceof \OpenTelemetry\Sdk\Trace\Tracer) {
                    $tracer->getTracerProvider()->shutdown();
                }
            }
        });
    }

    protected function registerWatchers()
    {
        if (config('opentelemetry.enabled') === false) {
            return;
        }

        if (config('opentelemetry.exporter') === null) {
            return;
        }

        $app = app();

        foreach (config('opentelemetry.watchers') as $key => $options) {
            if ($options === false) {
                continue;
            }

            if (is_array($options) && ! ($options['enabled'] ?? true)) {
                continue;
            }

            /** @var Watcher $watcher */
            $watcher = $app->make($key, [
                'options' => is_array($options) ? $options : [],
            ]);

            $watcher->register($app);
        }
    }
}
