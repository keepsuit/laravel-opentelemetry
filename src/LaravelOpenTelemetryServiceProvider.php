<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Composer\InstalledVersions;
use Illuminate\Support\Arr;
use Illuminate\Support\Env;
use Illuminate\Support\Str;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\Instrumentation;
use Keepsuit\LaravelOpenTelemetry\Support\CarbonClock;
use Keepsuit\LaravelOpenTelemetry\Support\PropagatorBuilder;
use Keepsuit\LaravelOpenTelemetry\Support\SamplerBuilder;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Signals;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;
use OpenTelemetry\Contrib\Otlp\HttpEndpointResolver;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\OtlpUtil;
use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpSpanExporter;
use OpenTelemetry\Contrib\Zipkin\Exporter as ZipkinExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Configuration\Variables as OTELVariables;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Logs\Exporter\ConsoleExporterFactory as LogsConsoleExporterFactory;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporterFactory as LogsInMemoryExporterFactory;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
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
    public function packageBooted(): void
    {
        $this->configureEnvironmentVariables();
        $this->init();
        $this->registerInstrumentation();
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-opentelemetry')
            ->hasConfigFile();
    }

    protected function init(): void
    {
        ClockFactory::setDefault(new CarbonClock());

        $resource = ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(Attributes::create([
                ResourceAttributes::SERVICE_NAME => config('opentelemetry.service_name'),
            ]))
        );

        $propagator = PropagatorBuilder::new()->build(config('opentelemetry.propagators'));

        /**
         * Traces
         */
        $spanExporter = $this->buildSpanExporter();
        $this->app->bind(SpanExporterInterface::class, fn () => $spanExporter);
        $spanProcessor = (new BatchSpanProcessorBuilder($spanExporter))->build();

        $samplerConfig = config('opentelemetry.traces.sampler', []);
        $sampler = SamplerBuilder::new()->build(
            $samplerConfig['type'] ?? 'always_on',
            $samplerConfig['parent'] ?? true,
            $samplerConfig['args'] ?? []
        );

        $tracerProvider = TracerProvider::builder()
            ->setResource($resource)
            ->addSpanProcessor($spanProcessor)
            ->setSampler($sampler)
            ->build();

        /**
         * Logs
         */
        $logExporter = $this->buildLogsExporter();
        $this->app->bind(LogRecordExporterInterface::class, fn () => $logExporter);
        $logProcessor = new BatchLogRecordProcessor(
            exporter: $logExporter,
            clock: ClockFactory::getDefault()
        );

        $loggerProvider = LoggerProvider::builder()
            ->setResource($resource)
            ->addLogRecordProcessor($logProcessor)
            ->build();

        Sdk::builder()
            ->setTracerProvider($tracerProvider)
            ->setLoggerProvider($loggerProvider)
            ->setPropagator($propagator)
            ->setAutoShutdown(true)
            ->buildAndRegisterGlobal();

        $instrumentation = new CachedInstrumentation(
            name: 'laravel-opentelemetry',
            version: class_exists(InstalledVersions::class) ? InstalledVersions::getPrettyVersion('keepsuit/laravel-opentelemetry') : null,
            schemaUrl: TraceAttributes::SCHEMA_URL,
        );

        $this->app->bind(TextMapPropagatorInterface::class, fn () => $propagator);
        $this->app->bind(TracerInterface::class, fn () => $instrumentation->tracer());
        $this->app->bind(LoggerInterface::class, fn () => $instrumentation->logger());

        $this->app->terminating(function () use ($loggerProvider, $tracerProvider) {
            $tracerProvider->forceFlush();
            $loggerProvider->forceFlush();
        });
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

    protected function buildSpanExporter(): SpanExporterInterface
    {
        $tracesExporter = config('opentelemetry.traces.exporter');
        $tracesExporterConfig = config(sprintf('opentelemetry.exporters.%s', $tracesExporter));
        $tracesExporterDriver = is_array($tracesExporterConfig) ? $tracesExporterConfig['driver'] : $tracesExporter;

        return match ($tracesExporterDriver) {
            'zipkin' => new ZipkinExporter(
                PsrTransportFactory::discover()->create(
                    Str::of(Arr::get($tracesExporterConfig ?? [], 'endpoint'))->rtrim('/')->append('/api/v2/spans')->toString(),
                    'application/json'
                ),
            ),
            'otlp' => new OtlpSpanExporter($this->buildOtlpTransport($tracesExporterConfig ?? [])),
            'console' => (new ConsoleSpanExporterFactory())->create(),
            default => (new InMemorySpanExporterFactory())->create(),
        };
    }

    protected function buildLogsExporter(): LogRecordExporterInterface
    {
        $logsExporter = config('opentelemetry.logs.exporter');
        $logsExporterConfig = config(sprintf('opentelemetry.exporters.%s', $logsExporter));
        $logsExporterDriver = is_array($logsExporterConfig) ? $logsExporterConfig['driver'] : $logsExporter;

        return match ($logsExporterDriver) {
            'otlp' => new LogsExporter($this->buildOtlpTransport($logsExporterConfig ?? [])),
            'console' => (new LogsConsoleExporterFactory())->create(),
            default => (new LogsInMemoryExporterFactory())->create()
        };
    }

    protected function buildOtlpTransport(array $config): TransportInterface
    {
        $protocol = $config['protocol'] ?? null;
        $endpoint = $config['endpoint'] ?? 'http://localhost:4318';

        return match ($protocol) {
            'grpc' => (new GrpcTransportFactory())->create($endpoint.OtlpUtil::method(Signals::TRACE)),
            'http/json', 'json' => (new OtlpHttpTransportFactory())->create(
                (new HttpEndpointResolver())->resolveToString($endpoint, Signals::TRACE),
                'application/json'
            ),
            default => (new OtlpHttpTransportFactory())->create(
                (new HttpEndpointResolver())->resolveToString($endpoint, Signals::TRACE),
                'application/x-protobuf'
            ),
        };
    }
}
