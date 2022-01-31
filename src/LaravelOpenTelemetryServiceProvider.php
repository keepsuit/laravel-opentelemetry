<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Keepsuit\LaravelOpenTelemetry\Watchers\Watcher;
use OpenTelemetry\Contrib\Jaeger\Exporter as JaegerExporter;
use OpenTelemetry\Contrib\Zipkin\Exporter as ZipkinExporter;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
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
        $this->app->singleton(Tracer::class);

        $this->app->singleton(TracerProvider::class, function () {
            $exporter = match (config('opentelemetry.exporter')) {
                'jaeger' => JaegerExporter::fromConnectionString(
                    config('opentelemetry.exporters.jaeger.endpoint'),
                    config('opentelemetry.service_name'),
                ),
                'zipkin' => ZipkinExporter::fromConnectionString(
                    config('opentelemetry.exporters.zipkin.endpoint'),
                    config('opentelemetry.service_name'),
                ),
                default => null
            };

            $sampler = value(function () use ($exporter) {
                if ($exporter === null) {
                    return null;
                }

                $enabled = config('opentelemetry.enabled', true);

                if ($enabled === 'parent') {
                    return new ParentBased(new AlwaysOffSampler());
                }

                return $enabled ? new AlwaysOnSampler() : new AlwaysOffSampler();
            });

            return tap(new TracerProvider(
                spanProcessors: [new BatchSpanProcessor($exporter)],
                sampler: $sampler
            ))->getTracer();
        });

        $this->app->terminating(function () {
            if (app()->resolved(TracerProvider::class)) {
                $tracer = app(TracerProvider::class);

                if ($tracer instanceof TracerProvider) {
                    $tracer->shutdown();
                }
            }

            $this->app->forgetInstance(TracerProvider::class);
            $this->app->forgetInstance(Tracer::class);
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

        foreach (config('opentelemetry.watchers') as $key => $options) {
            if ($options === false) {
                continue;
            }

            if (is_array($options) && ! ($options['enabled'] ?? true)) {
                continue;
            }

            /** @var Watcher $watcher */
            $watcher = $this->app->make($key, [
                'options' => is_array($options) ? $options : [],
            ]);

            $watcher->register($this->app);
        }
    }
}
