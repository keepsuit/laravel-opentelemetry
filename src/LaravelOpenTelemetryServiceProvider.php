<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Illuminate\Support\Env;
use Illuminate\Support\Str;
use Keepsuit\LaravelOpenTelemetry\Watchers\Watcher;
use OpenTelemetry\API\Common\Signal\Signals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;
use OpenTelemetry\Contrib\Jaeger\Exporter as JaegerExporter;
use OpenTelemetry\Contrib\Jaeger\HttpCollectorExporter as JaegerHttpCollectorExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\OtlpUtil;
use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpSpanExporter;
use OpenTelemetry\Contrib\Zipkin\Exporter as ZipkinExporter;
use OpenTelemetry\Extension\Propagator\B3\B3MultiPropagator;
use OpenTelemetry\Extension\Propagator\B3\B3SinglePropagator;
use OpenTelemetry\SDK\Common\Environment\Variables as OTELVariables;
use OpenTelemetry\SDK\Common\Otlp\HttpEndpointResolver;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
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
        $this->app->scoped(Tracer::class);

        $this->app->singleton(TextMapPropagatorInterface::class, function () {
            return match (config('opentelemetry.propagator')) {
                'b3' => B3SinglePropagator::getInstance(),
                'b3multi' => B3MultiPropagator::getInstance(),
                default => TraceContextPropagator::getInstance(),
            };
        });

        $this->app->scoped(TracerProvider::class, function () {
            /** @var SpanExporterInterface|null $exporter */
            $exporter = match (config('opentelemetry.exporter')) {
                'jaeger' => JaegerExporter::fromConnectionString(
                    Str::of(config('opentelemetry.exporters.jaeger.endpoint'))->rtrim('/')->append('/api/v2/spans')->toString(),
                    config('opentelemetry.service_name'),
                ),
                'jaeger-http' => JaegerHttpCollectorExporter::fromConnectionString(
                    Str::of(config('opentelemetry.exporters.jaeger-http.endpoint'))->rtrim('/')->append('/api/traces')->toString(),
                    config('opentelemetry.service_name'),
                ),
                'zipkin' => ZipkinExporter::fromConnectionString(
                    Str::of(config('opentelemetry.exporters.zipkin.endpoint'))->rtrim('/')->append('/api/v2/spans')->toString(),
                    config('opentelemetry.service_name'),
                ),
                'otlp-http' => new OtlpSpanExporter(
                    (new OtlpHttpTransportFactory())->create(
                        (new HttpEndpointResolver())->resolveToString(config('opentelemetry.exporters.otlp-http.endpoint'), Signals::TRACE),
                        'application/x-protobuf'
                    )
                ),
                'otlp-grpc' => new OtlpSpanExporter(
                    (new GrpcTransportFactory())->create(config('opentelemetry.exporters.otlp-grpc.endpoint').OtlpUtil::method(Signals::TRACE))
                ),
                'console' => ConsoleSpanExporter::fromConnectionString(),
                default => null
            };

            /** @var SamplerInterface $sampler */
            $sampler = value(function () use ($exporter): SamplerInterface {
                if ($exporter === null) {
                    return new AlwaysOffSampler();
                }

                $enabled = config('opentelemetry.enabled', true);

                if ($enabled === 'parent') {
                    return new ParentBased(new AlwaysOffSampler());
                }

                return $enabled ? new AlwaysOnSampler() : new AlwaysOffSampler();
            });

            return new TracerProvider(
                spanProcessors: $exporter !== null ? new BatchSpanProcessor($exporter, ClockFactory::getDefault()) : null,
                sampler: $sampler
            );
        });

        $this->app->terminating(function () {
            if (app()->resolved(Tracer::class)) {
                $tracer = app(Tracer::class);

                if ($tracer instanceof Tracer) {
                    $tracer->terminate();
                }
            }
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

        $envRepository->set(OTELVariables::OTEL_SERVICE_NAME, config('opentelemetry.service_name'));
    }
}
