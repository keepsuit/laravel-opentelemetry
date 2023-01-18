<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Composer\InstalledVersions;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Env;
use Illuminate\Support\Str;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Support\CarbonClock;
use Keepsuit\LaravelOpenTelemetry\Watchers\Watcher;
use OpenTelemetry\API\Common\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Common\Signal\Signals;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\OtlpUtil;
use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpSpanExporter;
use OpenTelemetry\Contrib\Zipkin\Exporter as ZipkinExporter;
use OpenTelemetry\Extension\Propagator\B3\B3MultiPropagator;
use OpenTelemetry\Extension\Propagator\B3\B3SinglePropagator;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Configuration\Variables as OTELVariables;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Common\Otlp\HttpEndpointResolver;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporterFactory;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemorySpanExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessorBuilder;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelOpenTelemetryServiceProvider extends PackageServiceProvider
{
    public function bootingPackage(): void
    {
        $this->configureEnvironmentVariables();
        $this->initTracer();
        $this->registerMacros();
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
        ClockFactory::setDefault(new CarbonClock());

        $resource = ResourceInfoFactory::merge(
            ResourceInfoFactory::defaultResource(),
            ResourceInfo::create(Attributes::create([
                ResourceAttributes::SERVICE_NAME => config('opentelemetry.service_name'),
            ]))
        );

        $metricExporter = match (config('opentelemetry.exporter')) {
            'http' => new MetricExporter(
                (new OtlpHttpTransportFactory())->create(
                    (new HttpEndpointResolver())->resolveToString(config('opentelemetry.exporters.http.endpoint'), Signals::METRICS),
                    'application/x-protobuf'
                )
            ),
            'grpc' => new MetricExporter(
                (new GrpcTransportFactory())->create(config('opentelemetry.exporters.grpc.endpoint').OtlpUtil::method(Signals::METRICS))
            ),
            default => (new InMemoryExporterFactory())->create(),
        };

        $metricReader = new ExportingReader($metricExporter, ClockFactory::getDefault());

        $meterProvider = MeterProvider::builder()
            ->setResource($resource)
            ->addReader($metricReader)
            ->build();

        $spanExporter = match (config('opentelemetry.exporter')) {
            'zipkin' => new ZipkinExporter(
                PsrTransportFactory::discover()->create(
                    Str::of(config('opentelemetry.exporters.zipkin.endpoint'))->rtrim('/')->append('/api/v2/spans')->toString(),
                    'application/json'
                ),
            ),
            'http' => new OtlpSpanExporter(
                (new OtlpHttpTransportFactory())->create(
                    (new HttpEndpointResolver())->resolveToString(config('opentelemetry.exporters.http.endpoint'), Signals::TRACE),
                    'application/x-protobuf'
                )
            ),
            'grpc' => new OtlpSpanExporter(
                (new GrpcTransportFactory())->create(config('opentelemetry.exporters.grpc.endpoint').OtlpUtil::method(Signals::TRACE))
            ),
            'console' => (new ConsoleSpanExporterFactory())->create(),
            default => (new InMemorySpanExporterFactory())->create(),
        };

        $spanProcessor = (new BatchSpanProcessorBuilder($spanExporter))
            ->setMeterProvider($meterProvider)
            ->build();

        $sampler = match (config('opentelemetry.enabled', true)) {
            'parent' => new ParentBased(new AlwaysOffSampler()),
            true => new AlwaysOnSampler(),
            default => new AlwaysOffSampler(),
        };

        $tracerProvider = TracerProvider::builder()
            ->addSpanProcessor($spanProcessor)
            ->setResource($resource)
            ->setSampler($sampler)
            ->build();

        $propagator = match (config('opentelemetry.propagator')) {
            'b3' => B3SinglePropagator::getInstance(),
            'b3multi' => B3MultiPropagator::getInstance(),
            default => TraceContextPropagator::getInstance(),
        };

        Sdk::builder()
            ->setMeterProvider($meterProvider)
            ->setTracerProvider($tracerProvider)
            ->setPropagator($propagator)
            ->setAutoShutdown(true)
            ->buildAndRegisterGlobal();

        $instrumentation = new CachedInstrumentation(
            'laravel-opentelemetry',
            class_exists(InstalledVersions::class) ? InstalledVersions::getPrettyVersion('keepsuit/laravel-opentelemetry') : null
        );

        $this->app->bind(TracerInterface::class, fn () => $instrumentation->tracer());
        $this->app->bind(MeterInterface::class, fn () => $instrumentation->meter());
        $this->app->bind(TextMapPropagatorInterface::class, fn () => $propagator);
        $this->app->bind(SpanExporterInterface::class, fn () => $spanExporter);

        $this->app->terminating(fn () => $tracerProvider->forceFlush());
    }

    protected function registerWatchers(): void
    {
        if (config('opentelemetry.enabled') === false) {
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

    private function registerMacros(): void
    {
        PendingRequest::macro('withTrace', function () {
            /** @var PendingRequest $this */
            return $this->withHeaders(Tracer::propagationHeaders());
        });
    }

    private function configureEnvironmentVariables(): void
    {
        $envRepository = Env::getRepository();

        $envRepository->set(OTELVariables::OTEL_SERVICE_NAME, config('opentelemetry.service_name'));
    }
}
