<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Illuminate\Support\Env;
use Keepsuit\LaravelOpenTelemetry\Watchers\Watcher;
use OpenTelemetry\Contrib\Jaeger\Exporter as JaegerExporter;
use OpenTelemetry\Contrib\Jaeger\HttpCollectorExporter as JaegerHttpCollectorExporter;
use OpenTelemetry\Contrib\OtlpGrpc\Exporter as OtlpGrpcExporter;
use OpenTelemetry\Contrib\OtlpHttp\Exporter as OtlpHttpExporter;
use OpenTelemetry\Contrib\Zipkin\Exporter as ZipkinExporter;
use OpenTelemetry\SDK\Common\Environment\Variables as OTELVariables;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelOpenTelemetryServiceProvider extends PackageServiceProvider
{
    public function register()
    {
        parent::register();

        $this->configureEnvironmentVariables();
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
                'jaeger-http' => JaegerHttpCollectorExporter::fromConnectionString(
                    config('opentelemetry.exporters.jaeger-http.endpoint'),
                    config('opentelemetry.service_name'),
                ),
                'zipkin' => ZipkinExporter::fromConnectionString(
                    config('opentelemetry.exporters.zipkin.endpoint'),
                    config('opentelemetry.service_name'),
                ),
                'otlp-http' => OtlpHttpExporter::fromConnectionString(
                    config('opentelemetry.exporters.otlp-http.endpoint'),
                    config('opentelemetry.service_name'),
                ),
                'otlp-grpc' => OtlpGrpcExporter::fromConnectionString(
                    config('opentelemetry.exporters.otlp-grpc.endpoint'),
                    config('opentelemetry.service_name'),
                ),
                'console' => ConsoleSpanExporter::fromConnectionString(),
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
            if (app()->resolved(Tracer::class)) {
                $tracer = app(Tracer::class);

                if ($tracer instanceof Tracer) {
                    $tracer->terminate();
                }
            }

            $this->app->forgetInstance(TracerProvider::class);
            $this->app->forgetInstance(Tracer::class);
        });
    }

    protected function registerWatchers(): void
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

    private function configureEnvironmentVariables(): void
    {
        $envRepository = Env::getRepository();

        if (config('opentelemetry.exporters.otlp-http.endpoint')) {
            $envRepository->set(OTELVariables::OTEL_EXPORTER_OTLP_TRACES_ENDPOINT, config('opentelemetry.exporters.otlp-http.endpoint'));
        }

        $envRepository->set(OTELVariables::OTEL_SERVICE_NAME, config('opentelemetry.service_name'));
    }
}
