<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Composer\InstalledVersions;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Keepsuit\LaravelOpenTelemetry\Support\CarbonClock;
use Keepsuit\LaravelOpenTelemetry\Support\OpenTelemetryMonologHandler;
use Keepsuit\LaravelOpenTelemetry\Support\PropagatorBuilder;
use Keepsuit\LaravelOpenTelemetry\Support\SamplerBuilder;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Signals;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;
use OpenTelemetry\Contrib\Otlp\HttpEndpointResolver;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\OtlpUtil;
use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpSpanExporter;
use OpenTelemetry\Contrib\Zipkin\Exporter as ZipkinExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Configuration\Parser\MapParser;
use OpenTelemetry\SDK\Common\Configuration\Variables as OTELVariables;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Logs\Exporter\ConsoleExporterFactory as LogsConsoleExporterFactory;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporterFactory as LogsInMemoryExporterFactory;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\ConsoleMetricExporterFactory;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporterFactory;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\MetricReaderInterface;
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
        if (Sdk::isDisabled()) {
            return;
        }

        $this->configureEnvironmentVariables();
        $this->injectConfig();
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
        Clock::setDefault(new CarbonClock);

        $resource = ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(Attributes::create([
                ResourceAttributes::SERVICE_NAME => config('opentelemetry.service_name'),
            ]))
        );

        $propagator = PropagatorBuilder::new()->build(config('opentelemetry.propagators'));
        $this->app->bind(TextMapPropagatorInterface::class, fn () => $propagator);

        /**
         * Metrics
         */
        $metricsExporter = $this->buildMetricsExporter();
        $this->app->bind(MetricExporterInterface::class, fn () => $metricsExporter);
        $metricsReader = new ExportingReader($metricsExporter);
        $this->app->bind(MetricReaderInterface::class, fn () => $metricsReader);

        $meterProvider = MeterProvider::builder()
            ->setResource($resource)
            ->addReader($metricsReader)
            ->build();

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
            clock: Clock::getDefault()
        );

        $loggerProvider = LoggerProvider::builder()
            ->setResource($resource)
            ->addLogRecordProcessor($logProcessor)
            ->build();

        Sdk::builder()
            ->setTracerProvider($tracerProvider)
            ->setLoggerProvider($loggerProvider)
            ->setMeterProvider($meterProvider)
            ->setPropagator($propagator)
            ->setAutoShutdown(true)
            ->buildAndRegisterGlobal();

        $instrumentation = new CachedInstrumentation(
            name: 'laravel-opentelemetry',
            version: class_exists(InstalledVersions::class) ? InstalledVersions::getPrettyVersion('keepsuit/laravel-opentelemetry') : null,
            schemaUrl: TraceAttributes::SCHEMA_URL,
        );

        $this->app->bind(MeterInterface::class, fn () => $instrumentation->meter());
        $this->app->bind(TracerInterface::class, fn () => $instrumentation->tracer());
        $this->app->bind(LoggerInterface::class, fn () => $instrumentation->logger());

        $flushCallback = function () use ($loggerProvider, $tracerProvider, $meterProvider) {
            $tracerProvider->forceFlush();
            $loggerProvider->forceFlush();
            $meterProvider->forceFlush();
        };

        Queue::looping($flushCallback);

        $this->app->terminating($flushCallback);
    }

    protected function registerInstrumentation(): void
    {
        $this->app->booted(function (Application $app) {
            $app->register(InstrumentationServiceProvider::class);
        });
    }

    private function configureEnvironmentVariables(): void
    {
        $envRepository = Env::getRepository();

        $envRepository->set(OTELVariables::OTEL_SERVICE_NAME, config('opentelemetry.service_name'));

        // Disable debug scopes wrapping
        $envRepository->set('OTEL_PHP_DEBUG_SCOPES_DISABLED', '1');
    }

    protected function buildMetricsExporter(): MetricExporterInterface
    {
        $metricsExporter = config('opentelemetry.metrics.exporter');
        $metricsExporterConfig = config(sprintf('opentelemetry.exporters.%s', $metricsExporter));
        $metricsExporterDriver = is_array($metricsExporterConfig) ? $metricsExporterConfig['driver'] : $metricsExporter;

        return match ($metricsExporterDriver) {
            'otlp' => new MetricExporter($this->buildOtlpTransport($metricsExporterConfig ?? [], Signals::METRICS)),
            'console' => (new ConsoleMetricExporterFactory)->create(),
            default => (new InMemoryExporterFactory)->create(),
        };
    }

    protected function buildSpanExporter(): SpanExporterInterface
    {
        $tracesExporter = config('opentelemetry.traces.exporter');
        $tracesExporterConfig = config(sprintf('opentelemetry.exporters.%s', $tracesExporter));
        $tracesExporterDriver = is_array($tracesExporterConfig) ? $tracesExporterConfig['driver'] : $tracesExporter;

        return match ($tracesExporterDriver) {
            'zipkin' => $this->buildZipkinExporter($tracesExporterConfig ?? []),
            'otlp' => new OtlpSpanExporter($this->buildOtlpTransport($tracesExporterConfig ?? [], Signals::TRACE)),
            'console' => (new ConsoleSpanExporterFactory)->create(),
            default => (new InMemorySpanExporterFactory)->create(),
        };
    }

    protected function buildLogsExporter(): LogRecordExporterInterface
    {
        $logsExporter = config('opentelemetry.logs.exporter');
        $logsExporterConfig = config(sprintf('opentelemetry.exporters.%s', $logsExporter));
        $logsExporterDriver = is_array($logsExporterConfig) ? $logsExporterConfig['driver'] : $logsExporter;

        return match ($logsExporterDriver) {
            'otlp' => new LogsExporter($this->buildOtlpTransport($logsExporterConfig ?? [], Signals::LOGS)),
            'console' => (new LogsConsoleExporterFactory)->create(),
            default => (new LogsInMemoryExporterFactory)->create()
        };
    }

    /**
     * @phpstan-param Signals::TRACE|Signals::METRICS|Signals::LOGS $signal
     */
    protected function buildOtlpTransport(array $config, string $signal): TransportInterface
    {
        $protocol = $config['protocol'] ?? null;
        $endpoint = $config['endpoint'] ?? 'http://localhost:4318';

        $maxRetries = $config['max_retries'] ?? 3;

        $timeoutMillis = match ($signal) {
            Signals::TRACE => $config['traces_timeout'] ?? 10000,
            Signals::METRICS => $config['metrics_timeout'] ?? 10000,
            Signals::LOGS => $config['logs_timeout'] ?? 10000,
        };

        $headers = match ($signal) {
            Signals::TRACE => $config['traces_headers'] ?? [],
            Signals::METRICS => $config['metrics_headers'] ?? [],
            Signals::LOGS => $config['logs_headers'] ?? [],
        };

        $headers = rescue(
            fn () => is_string($headers) ? MapParser::parse($headers) : $headers,
            [],
            report: false
        );

        return match ($protocol) {
            'grpc' => (new GrpcTransportFactory)->create(
                endpoint: $endpoint.OtlpUtil::method($signal),
                headers: $headers,
                maxRetries: $maxRetries,
            ),
            'http/json', 'json' => (new OtlpHttpTransportFactory)->create(
                endpoint: (new HttpEndpointResolver)->resolveToString($endpoint, $signal),
                contentType: 'application/json',
                headers: $headers,
                timeout: $timeoutMillis / 1000,
                maxRetries: $maxRetries
            ),
            default => (new OtlpHttpTransportFactory)->create(
                endpoint: (new HttpEndpointResolver)->resolveToString($endpoint, $signal),
                contentType: 'application/x-protobuf',
                headers: $headers,
                timeout: $timeoutMillis / 1000,
                maxRetries: $maxRetries,
            ),
        };
    }

    protected function buildZipkinExporter(array $config): ZipkinExporter
    {
        $endpoint = Str::of(Arr::get($config, 'endpoint', ''))->rtrim('/')->append('/api/v2/spans')->toString();
        $maxRetries = $config['max_retries'] ?? 3;
        $timeoutMillis = $config['timeout'] ?? 10000;

        return new ZipkinExporter(
            (new PsrTransportFactory(
                Psr18ClientDiscovery::find(),
                Psr17FactoryDiscovery::findRequestFactory(),
                Psr17FactoryDiscovery::findStreamFactory(),
            ))->create(
                endpoint: $endpoint,
                contentType: 'application/json',
                timeout: $timeoutMillis / 1000,
                maxRetries: $maxRetries,
            ),
        );
    }

    protected function injectConfig(): void
    {
        $this->callAfterResolving(Repository::class, function (Repository $config) {
            if ($config->has('logging.channels.otlp')) {
                return;
            }

            $config->set('logging.channels.otlp', [
                'driver' => 'monolog',
                'handler' => OpenTelemetryMonologHandler::class,
                'level' => 'debug',
            ]);
        });
    }
}
