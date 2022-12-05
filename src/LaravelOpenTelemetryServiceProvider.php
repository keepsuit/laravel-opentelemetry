<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
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
use OpenTelemetry\SDK\Common\Configuration\Variables as OTELVariables;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Common\Otlp\HttpEndpointResolver;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemorySpanExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessorBuilder;
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
            /** @var SpanExporterInterface $exporter */
            $exporter = match (config('opentelemetry.exporter')) {
                'jaeger' => new JaegerExporter(
                    config('opentelemetry.service_name'),
                    PsrTransportFactory::discover()->create(
                        Str::of(config('opentelemetry.exporters.jaeger.endpoint'))->rtrim('/')->append('/api/v2/spans')->toString(),
                        'application/json'
                    ),
                ),
                'jaeger-http' => new JaegerHttpCollectorExporter(
                    Str::of(config('opentelemetry.exporters.jaeger-http.endpoint'))->rtrim('/')->append('/api/traces')->toString(),
                    config('opentelemetry.service_name'),
                    Psr18ClientDiscovery::find(),
                    Psr17FactoryDiscovery::findRequestFactory(),
                    Psr17FactoryDiscovery::findStreamFactory(),
                ),
                'zipkin' => new ZipkinExporter(
                    config('opentelemetry.service_name'),
                    PsrTransportFactory::discover()->create(
                        Str::of(config('opentelemetry.exporters.zipkin.endpoint'))->rtrim('/')->append('/api/v2/spans')->toString(),
                        'application/json'
                    ),
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
                'console' => (new ConsoleSpanExporterFactory())->create(),
                default => (new InMemorySpanExporterFactory())->create(),
            };

            /** @var SamplerInterface $sampler */
            $sampler = value(function (): SamplerInterface {
                $enabled = config('opentelemetry.enabled', true);

                if ($enabled === 'parent') {
                    return new ParentBased(new AlwaysOffSampler());
                }

                return $enabled ? new AlwaysOnSampler() : new AlwaysOffSampler();
            });

            return TracerProvider::builder()
                ->addSpanProcessor((new BatchSpanProcessorBuilder($exporter))->build())
                ->setResource(ResourceInfoFactory::defaultResource())
                ->setSampler($sampler)
                ->build();
        });

        $this->app->terminating(function () {
            if (app()->resolved(TracerProvider::class)) {
                app(TracerProvider::class)->shutdown();
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
