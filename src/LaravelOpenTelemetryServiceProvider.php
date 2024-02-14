<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Composer\InstalledVersions;
use Illuminate\Support\Env;
use Illuminate\Support\Str;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\Instrumentation;
use Keepsuit\LaravelOpenTelemetry\Support\CarbonClock;
use Keepsuit\LaravelOpenTelemetry\Support\PropagatorBuilder;
use Keepsuit\LaravelOpenTelemetry\Support\SamplerBuilder;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Signals;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;
use OpenTelemetry\Contrib\Otlp\HttpEndpointResolver;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\OtlpUtil;
use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpSpanExporter;
use OpenTelemetry\Contrib\Zipkin\Exporter as ZipkinExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Configuration\Variables as OTELVariables;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemorySpanExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessorBuilder;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;
use OpenTelemetry\SemConv\TraceAttributes;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelOpenTelemetryServiceProvider extends PackageServiceProvider
{
    public function bootingPackage(): void
    {
        $this->configureEnvironmentVariables();
        $this->initTracer();
        $this->registerInstrumentation();
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

        $resource = ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(Attributes::create([
                ResourceAttributes::SERVICE_NAME => config('opentelemetry.service_name'),
            ]))
        );

        $spanExporter = match (config('opentelemetry.exporter')) {
            'zipkin' => new ZipkinExporter(
                PsrTransportFactory::discover()->create(
                    Str::of(config('opentelemetry.exporters.zipkin.endpoint'))->rtrim('/')->append('/api/v2/spans')->toString(),
                    'application/json'
                ),
            ),
            'http' => new OtlpSpanExporter(
                // @phpstan-ignore-next-line
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
            ->build();

        $sampler = SamplerBuilder::new()->build(
            config('opentelemetry.sampler.type'),
            config('opentelemetry.sampler.parent'),
            config('opentelemetry.sampler.args', [])
        );

        $tracerProvider = TracerProvider::builder()
            ->addSpanProcessor($spanProcessor)
            ->setResource($resource)
            ->setSampler($sampler)
            ->build();

        $propagator = PropagatorBuilder::new()->build(config('opentelemetry.propagators'));

        Sdk::builder()
            ->setTracerProvider($tracerProvider)
            ->setPropagator($propagator)
            ->setAutoShutdown(true)
            ->buildAndRegisterGlobal();

        $instrumentation = new CachedInstrumentation(
            name: 'laravel-opentelemetry',
            version: class_exists(InstalledVersions::class) ? InstalledVersions::getPrettyVersion('keepsuit/laravel-opentelemetry') : null,
            schemaUrl: TraceAttributes::SCHEMA_URL,
        );

        $this->app->bind(TracerInterface::class, fn () => $instrumentation->tracer());
        $this->app->bind(TextMapPropagatorInterface::class, fn () => $propagator);
        $this->app->bind(SpanExporterInterface::class, fn () => $spanExporter);

        $this->app->terminating(fn () => $tracerProvider->forceFlush());
    }

    protected function registerInstrumentation(): void
    {
        if (Sdk::isDisabled()) {
            return;
        }

        foreach (config('opentelemetry.instrumentation') as $key => $options) {
            if ($options === false) {
                continue;
            }

            if (is_array($options) && ! ($options['enabled'] ?? true)) {
                continue;
            }

            $watcher = $this->app->make($key);

            if ($watcher instanceof Instrumentation) {
                $watcher->register(is_array($options) ? $options : []);
            }
        }
    }

    private function configureEnvironmentVariables(): void
    {
        $envRepository = Env::getRepository();

        $envRepository->set(OTELVariables::OTEL_SERVICE_NAME, config('opentelemetry.service_name'));

        // Disable debug scopes wrapping
        $envRepository->set('OTEL_PHP_DEBUG_SCOPES_DISABLED', '1');
    }
}
