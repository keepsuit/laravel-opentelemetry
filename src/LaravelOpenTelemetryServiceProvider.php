<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Composer\InstalledVersions;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Env;
use Illuminate\Support\Str;
use Keepsuit\LaravelOpenTelemetry\Support\CarbonClock;
use Keepsuit\LaravelOpenTelemetry\Support\OpenTelemetryMonologHandler;
use Keepsuit\LaravelOpenTelemetry\Support\PropagatorBuilder;
use Keepsuit\LaravelOpenTelemetry\Support\ResourceBuilder;
use Keepsuit\LaravelOpenTelemetry\Support\SamplerBuilder;
use Keepsuit\LaravelOpenTelemetry\Support\UserContextResolver;
use Keepsuit\LaravelOpenTelemetry\TailSampling\TailSamplingProcessor;
use Keepsuit\LaravelOpenTelemetry\TailSampling\TailSamplingRuleInterface;
use Keepsuit\LaravelOpenTelemetry\WorkerMode\WorkerModeDetectorInterface;
use Keepsuit\LaravelOpenTelemetry\WorkerMode\WorkerModeManager;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Signals;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\HttpEndpointResolver;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\OtlpUtil;
use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpSpanExporter;
use OpenTelemetry\Contrib\Zipkin\Exporter as ZipkinExporter;
use OpenTelemetry\SDK\Common\Configuration\Parser\MapParser;
use OpenTelemetry\SDK\Common\Configuration\Variables as OTELVariables;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Logs\Exporter\ConsoleExporterFactory as LogsConsoleExporterFactory;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporterFactory as LogsInMemoryExporterFactory;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Logs\LogRecordProcessorInterface;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\ConsoleMetricExporterFactory;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporterFactory;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\MetricReaderInterface;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\NoopTracerProvider;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemorySpanExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use OpenTelemetry\SemConv\Version;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;

class LaravelOpenTelemetryServiceProvider extends PackageServiceProvider
{
    public function packageRegistered(): void
    {
        $this->app->singleton(\Keepsuit\LaravelOpenTelemetry\Meter::class);
        $this->app->singleton(\Keepsuit\LaravelOpenTelemetry\Tracer::class);
        $this->app->singleton(\Keepsuit\LaravelOpenTelemetry\Logger::class);
        $this->app->singleton(\Keepsuit\LaravelOpenTelemetry\OpenTelemetry::class);
        $this->app->singleton(UserContextResolver::class);

        $this->configureEnvironmentVariables();
        $this->injectConfig();
        $this->initWorkerModeManager();
    }

    public function packageBooted(): void
    {
        $this->initOtelSdk();
        $this->bootSingletons();
        $this->registerInstrumentation();
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-opentelemetry')
            ->hasConfigFile();
    }

    protected function initOtelSdk(): void
    {
        Clock::setDefault(new CarbonClock);

        $resource = ResourceBuilder::build();

        $propagator = match (Sdk::isDisabled()) {
            true => new NoopTextMapPropagator,
            false => PropagatorBuilder::new()->build(config('opentelemetry.propagators'))
        };

        $meterProvider = $this->buildMeterProvider($resource);
        $tracerProvider = $this->buildTracerProvider($resource, meterProvider: $meterProvider);
        $loggerProvider = $this->buildLoggerProvider($resource, meterProvider: $meterProvider);

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
            schemaUrl: Version::VERSION_1_36_0->url(),
        );

        $this->app->singleton(TextMapPropagatorInterface::class, fn () => $propagator);
        $this->app->singleton(MeterInterface::class, fn () => $instrumentation->meter());
        $this->app->singleton(TracerInterface::class, fn () => $instrumentation->tracer());
        $this->app->singleton(LoggerInterface::class, fn () => $instrumentation->logger());
    }

    protected function registerInstrumentation(): void
    {
        if (Sdk::isDisabled()) {
            return;
        }

        $this->app->booted(function (Application $app) {
            $app->register(InstrumentationServiceProvider::class);
        });

        $this->callAfterResolving(ExceptionHandlerContract::class, function (ExceptionHandlerContract $handler) {
            /** @phpstan-ignore-next-line */
            if (! method_exists($handler, 'reportable')) {
                return;
            }

            $handler->reportable(function (Throwable $e) {
                \Keepsuit\LaravelOpenTelemetry\Facades\Tracer::activeSpan()
                    ->recordException($e)
                    ->setStatus(StatusCode::STATUS_ERROR);
            });
        });
    }

    protected function configureEnvironmentVariables(): void
    {
        $envRepository = Env::getRepository();

        $envRepository->set(OTELVariables::OTEL_SERVICE_NAME, config('opentelemetry.service_name'));

        // Disable debug scopes wrapping
        $envRepository->set('OTEL_PHP_DEBUG_SCOPES_DISABLED', '1');
    }

    protected function buildTracerProvider(ResourceInfo $resource, MeterProviderInterface $meterProvider): TracerProviderInterface
    {
        $spanExporter = match (Sdk::isDisabled()) {
            true => (new InMemorySpanExporterFactory)->create(),
            false => $this->buildSpanExporter(),
        };
        $this->app->bind(SpanExporterInterface::class, fn () => $spanExporter);

        if (Sdk::isDisabled()) {
            return new NoopTracerProvider;
        }

        $batchProcessor = new BatchSpanProcessor(
            exporter: $spanExporter,
            clock: Clock::getDefault(),
            meterProvider: $meterProvider
        );

        $samplerConfig = config('opentelemetry.traces.sampler', []);
        $sampler = SamplerBuilder::new()->build(
            $samplerConfig['type'] ?? 'always_on',
            $samplerConfig['parent'] ?? true,
            $samplerConfig['args'] ?? []
        );

        $tailSamplingConfig = config('opentelemetry.traces.sampler.tail_sampling', []);
        $tailSamplingEnabled = (bool) ($tailSamplingConfig['enabled'] ?? false);

        $builder = TracerProvider::builder()
            ->setResource($resource)
            ->setSampler(match ($tailSamplingEnabled) {
                true => new AlwaysOnSampler,
                false => $sampler,
            })
            ->addSpanProcessor(match ($tailSamplingEnabled) {
                true => $this->buildTailSamplingProcessor($batchProcessor, $sampler, $tailSamplingConfig),
                false => $batchProcessor,
            });

        foreach (config('opentelemetry.traces.processors', []) as $processorClass) {
            if (class_exists($processorClass)) {
                $processor = $this->app->make($processorClass);

                if ($processor instanceof SpanProcessorInterface) {
                    $builder->addSpanProcessor($processor);
                }
            }
        }

        return $builder->build();
    }

    protected function buildMeterProvider(ResourceInfo $resource): MeterProviderInterface
    {
        $metricsExporter = match (Sdk::isDisabled()) {
            true => (new InMemoryExporterFactory)->create(),
            false => $this->buildMetricsExporter(),
        };
        $this->app->singleton(MetricExporterInterface::class, fn () => $metricsExporter);
        $metricsReader = new ExportingReader($metricsExporter);
        $this->app->singleton(MetricReaderInterface::class, fn () => $metricsReader);

        if (Sdk::isDisabled()) {
            return new NoopMeterProvider;
        }

        return MeterProvider::builder()
            ->setResource($resource)
            ->addReader($metricsReader)
            ->build();
    }

    protected function buildLoggerProvider(ResourceInfo $resource, MeterProviderInterface $meterProvider): LoggerProviderInterface
    {
        $logExporter = match (Sdk::isDisabled()) {
            true => (new LogsInMemoryExporterFactory)->create(),
            false => $this->buildLogsExporter(),
        };
        $this->app->bind(LogRecordExporterInterface::class, fn () => $logExporter);

        if (Sdk::isDisabled()) {
            return new NoopLoggerProvider;
        }

        $logProcessor = new BatchLogRecordProcessor(
            exporter: $logExporter,
            clock: Clock::getDefault(),
            meterProvider: $meterProvider
        );

        $builder = LoggerProvider::builder()
            ->setResource($resource)
            ->addLogRecordProcessor($logProcessor);

        foreach (config('opentelemetry.logs.processors', []) as $processorClass) {
            if (class_exists($processorClass)) {
                $processor = $this->app->make($processorClass);

                if ($processor instanceof LogRecordProcessorInterface) {
                    $builder->addLogRecordProcessor($processor);
                }
            }
        }

        return $builder->build();
    }

    protected function buildMetricsExporter(): MetricExporterInterface
    {
        $metricsExporter = config('opentelemetry.metrics.exporter');
        $metricsExporterConfig = config(sprintf('opentelemetry.exporters.%s', $metricsExporter));
        $metricsExporterDriver = is_array($metricsExporterConfig) ? $metricsExporterConfig['driver'] : $metricsExporter;

        return match ($metricsExporterDriver) {
            'otlp' => new MetricExporter(
                $this->buildOtlpTransport($metricsExporterConfig ?? [], Signals::METRICS),
                Arr::get($metricsExporterConfig, 'metrics_temporality')
            ),
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
     * @param  array{
     *     endpoint?: string,
     *     protocol?: string,
     *     max_retries?: int,
     *     traces_protocol?: string,
     *     traces_timeout?: int,
     *     traces_headers?: string|array<string, string>,
     *     metrics_protocol?: string,
     *     metrics_timeout?: int,
     *     metrics_headers?: string|array<string, string>,
     *     logs_protocol?: string,
     *     logs_timeout?: int,
     *     logs_headers?: string|array<string, string>,
     * }  $config
     * @param  Signals::TRACE|Signals::METRICS|Signals::LOGS  $signal
     * @return TransportInterface<ContentTypes::PROTOBUF|ContentTypes::JSON>
     */
    protected function buildOtlpTransport(array $config, string $signal): TransportInterface
    {
        $endpoint = $config['endpoint'] ?? 'http://localhost:4318';

        $maxRetries = $config['max_retries'] ?? 3;

        $protocol = match ($signal) {
            Signals::TRACE => $config['traces_protocol'] ?? null,
            Signals::METRICS => $config['metrics_protocol'] ?? null,
            Signals::LOGS => $config['logs_protocol'] ?? null,
        } ?? $config['protocol'] ?? null;

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

        /** @var array<string, string> $headers */
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

    /**
     * @param  array{
     *     rules?: array<class-string<TailSamplingRuleInterface>, array<string, mixed>|bool>,
     *     decision_wait?: int,
     * }  $config
     */
    protected function buildTailSamplingProcessor(SpanProcessorInterface $downstreamProcessor, SamplerInterface $sampler, array $config): SpanProcessorInterface
    {
        $rules = [];

        foreach (($config['rules'] ?? []) as $ruleClass => $options) {
            if (is_bool($options)) {
                $options = ['enabled' => $options];
            }

            if (! ($options['enabled'] ?? true)) {
                continue;
            }

            if (! class_exists($ruleClass)) {
                continue;
            }

            $rule = $this->app->make($ruleClass);

            if (! $rule instanceof TailSamplingRuleInterface) {
                continue;
            }

            $rule->initialize($options ?? []);
            $rules[] = $rule;
        }

        return new TailSamplingProcessor(
            $downstreamProcessor,
            $sampler,
            $rules,
            decisionWait: max(1, (int) ($config['decision_wait'] ?? 5000))
        );
    }

    protected function initWorkerModeManager(): void
    {
        $this->app->singleton(WorkerModeManager::class, function () {
            $detectors = Collection::make(config()->array('opentelemetry.worker_mode.detectors', []))
                ->map(function (string $detectorClass) {
                    if (! class_exists($detectorClass)) {
                        return null;
                    }

                    $detector = Container::getInstance()->make($detectorClass);

                    if (! $detector instanceof WorkerModeDetectorInterface) {
                        return null;
                    }

                    return $detector;
                })
                ->filter()
                ->all();

            return new WorkerModeManager(
                flushAfterEachIteration: config()->boolean('opentelemetry.worker_mode.flush_after_each_iteration', false),
                metricsExportInterval: config()->integer('opentelemetry.worker_mode.metrics_collect_interval', 60),
                detectors: $detectors
            );
        });
    }

    /**
     * This ensure that singletons are resolved before handling any request when running in worker mode.
     * This prevents they are flushed between requests.
     */
    protected function bootSingletons(): void
    {
        $singletons = [
            Meter::class,
            Tracer::class,
            Logger::class,
            OpenTelemetry::class,
            UserContextResolver::class,
            WorkerModeManager::class,
        ];

        foreach ($singletons as $singleton) {
            if ($this->app->bound($singleton)) {
                $this->app->make($singleton);
            }
        }
    }
}
