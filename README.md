# OpenTelemetry integration for laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/keepsuit/laravel-opentelemetry.svg?style=flat-square)](https://packagist.org/packages/keepsuit/laravel-opentelemetry)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/keepsuit/laravel-opentelemetry/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/keepsuit/laravel-opentelemetry/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/keepsuit/laravel-opentelemetry/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/keepsuit/laravel-opentelemetry/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/keepsuit/laravel-opentelemetry.svg?style=flat-square)](https://packagist.org/packages/keepsuit/laravel-opentelemetry)

_OpenTelemetry is a collection of tools, APIs, and SDKs. Use it to instrument, generate, collect, and export telemetry data (metrics, logs, and traces) to help you analyze your software’s performance and behavior._

This package allows you to integrate OpenTelemetry in a Laravel application.

- [Installation](#installation)
- [User Context](#user-context)
- [Instrumentations](#instrumentations)
    - [Http Server Requests](#http-server-requests)
    - [Http Client](#http-client)
    - [Database](#database)
    - [Queue Jobs](#queue-jobs)
    - [Redis](#redis)
    - [Cache](#cache)
    - [Events](#events)
    - [View](#view)
    - [Livewire](#livewire)
    - [Scout](#scout)
    - [Reverb](#reverb)
    - [Console Commands](#console-commands)
- [Traces](#traces)
    - [Manual Traces](#manual-traces)
    - [Trace Sampling](#trace-sampling)
    - [Logs Context](#logs-context)
- [Metrics](#metrics)
    - [Meter API](#meter-api)
    - [Metrics Temporality](#metrics-temporality)
    - [Metrics in per-request runtimes](#metrics-in-per-request-runtimes)
- [Logs](#logs)
- [Worker Mode](#worker-mode)
- [Export Failures](#export-failures)
- [Development Setup](#development-setup)
- [Testing](#testing)
- [Changelog](#changelog)
- [Credits](#credits)
- [License](#license)

## Installation

You can install the package via composer:

```bash
composer require keepsuit/laravel-opentelemetry
```

You can publish the config file with:

```bash
php artisan vendor:publish --provider="Keepsuit\LaravelOpenTelemetry\LaravelOpenTelemetryServiceProvider" --tag="opentelemetry-config"
```

This is the contents of the published config file:

```php
use Illuminate\Support\Str;
use Keepsuit\LaravelOpenTelemetry\Instrumentation;
use Keepsuit\LaravelOpenTelemetry\Support\ResourceAttributesParser;
use Keepsuit\LaravelOpenTelemetry\TailSampling;
use Keepsuit\LaravelOpenTelemetry\WorkerMode;
use OpenTelemetry\SDK\Common\Configuration\Variables;

return [
    /**
     * When set to true, Opentelemetry SDK will be disabled
     */
    'disabled' => env(Variables::OTEL_SDK_DISABLED, false),

    /**
     * Service name
     */
    'service_name' => env(Variables::OTEL_SERVICE_NAME, Str::slug((string) env('APP_NAME', 'laravel-app'))),

    /**
     * Service instance id
     * Should be unique for each instance of your service.
     * If not set, a random id will be generated on each request.
     */
    'service_instance_id' => env('OTEL_SERVICE_INSTANCE_ID'),

    /**
     * Additional resource attributes
     * Key-value pairs of resource attributes to add to all telemetry data.
     * By default, reads and parses OTEL_RESOURCE_ATTRIBUTES environment variable (which should be in the format 'key1=value1,key2=value2').
     */
    'resource_attributes' => ResourceAttributesParser::parse((string) env(Variables::OTEL_RESOURCE_ATTRIBUTES, '')),

    /**
     * Include authenticated user context on traces and logs.
     */
    'user_context' => env('OTEL_USER_CONTEXT', true),

    /**
     * Comma separated list of propagators to use.
     * Supports any otel propagator, for example: "tracecontext", "baggage", "b3", "b3multi", "none"
     */
    'propagators' => env(Variables::OTEL_PROPAGATORS, 'tracecontext'),

    /**
     * OpenTelemetry Meter configuration
     */
    'metrics' => [
        /**
         * Metrics exporter
         * This should be the key of one of the exporters defined in the exporters section
         * Supported drivers: "otlp", "console", "memory", "null"
         */
        'exporter' => env(Variables::OTEL_METRICS_EXPORTER, 'otlp'),
    ],

    /**
     * OpenTelemetry Traces configuration
     */
    'traces' => [
        /**
         * Traces exporter
         * This should be the key of one of the exporters defined in the exporters section
         * Supported drivers: "otlp", "zipkin", "console", "memory", "null"
         */
        'exporter' => env(Variables::OTEL_TRACES_EXPORTER, 'otlp'),

        /**
         * Traces sampler
         */
        'sampler' => [
            /**
             * Wraps the sampler in a parent based sampler
             */
            'parent' => env('OTEL_TRACES_SAMPLER_PARENT', true),

            /**
             * Sampler type
             * Supported values: "always_on", "always_off", "traceidratio"
             */
            'type' => env('OTEL_TRACES_SAMPLER_TYPE', 'always_on'),

            'args' => [
                /**
                 * Sampling ratio for traceidratio sampler
                 */
                'ratio' => env('OTEL_TRACES_SAMPLER_TRACEIDRATIO_RATIO', 0.05),
            ],

            'tail_sampling' => [
                'enabled' => env('OTEL_TRACES_TAIL_SAMPLING_ENABLED', false),
                // Maximum time to wait for the end of the trace before making a sampling decision (in milliseconds)
                'decision_wait' => (int) env('OTEL_TRACES_TAIL_SAMPLING_DECISION_WAIT', 5000),

                'rules' => [
                    TailSampling\Rules\ErrorsRule::class => env('OTEL_TRACES_TAIL_SAMPLING_RULE_KEEP_ERRORS', true),
                    TailSampling\Rules\SlowTraceRule::class => [
                        'enabled' => env('OTEL_TRACES_TAIL_SAMPLING_RULE_SLOW_TRACES', true),
                        'threshold_ms' => (int) env('OTEL_TRACES_TAIL_SAMPLING_SLOW_TRACES_THRESHOLD_MS', 2000),
                    ],
                ],
            ],
        ],

        /**
         * Traces span processors.
         * Processors classes must implement OpenTelemetry\SDK\Trace\SpanProcessorInterface
         *
         * Example: YourTracesSpanProcessor::class
         */
        'processors' => [],
    ],

    /**
     * OpenTelemetry logs configuration
     */
    'logs' => [
        /**
         * Logs exporter
         * This should be the key of one of the exporters defined in the exporters section
         * Supported drivers: "otlp", "console", "memory", "null"
         */
        'exporter' => env(Variables::OTEL_LOGS_EXPORTER, 'otlp'),

        /**
         * Inject active trace id in log context
         *
         * When using the OpenTelemetry logger, the trace id is always injected in the exported log record.
         * This option allows to inject the trace id in the log context for other loggers.
         */
        'inject_trace_id' => true,

        /**
         * Context field name for trace id
         */
        'trace_id_field' => 'trace_id',

        /**
         * Logs record processors.
         * Processors classes must implement OpenTelemetry\SDK\Logs\LogRecordProcessorInterface
         *
         * Example: YourLogRecordProcessor::class
         */
        'processors' => [],
    ],

    /**
     * OpenTelemetry exporters
     *
     * Here you can configure exports used by metrics, traces and logs.
     * If you want to use the same protocol with different endpoints,
     * you can copy the exporter with a different and change the endpoint
     *
     * Supported drivers: "otlp", "zipkin" (only traces), "console", "memory", "null"
     */
    'exporters' => [
        'otlp' => [
            'driver' => 'otlp',
            'endpoint' => env(Variables::OTEL_EXPORTER_OTLP_ENDPOINT, 'http://localhost:4318'),
            /**
             * Supported protocols: "grpc", "http/protobuf", "http/json"
             */
            'protocol' => env(Variables::OTEL_EXPORTER_OTLP_PROTOCOL, 'http/protobuf'),
            /**
             * Retries after a failed export. Exports are synchronous: every attempt against an unreachable
             * collector blocks the request, job or command for up to the timeout.
             */
            'max_retries' => (int) env('OTEL_EXPORTER_OTLP_MAX_RETRIES', 1),
            /**
             * Connection timeout in milliseconds, for http protocols when Guzzle is installed
             */
            'connect_timeout' => (int) env('OTEL_EXPORTER_OTLP_CONNECT_TIMEOUT', 1000),
            'traces_timeout' => (int) env(Variables::OTEL_EXPORTER_OTLP_TRACES_TIMEOUT, env(Variables::OTEL_EXPORTER_OTLP_TIMEOUT, 10000)),
            'traces_headers' => (string) env(Variables::OTEL_EXPORTER_OTLP_TRACES_HEADERS, env(Variables::OTEL_EXPORTER_OTLP_HEADERS, '')),
            /**
             * Override protocol for traces export
             */
            'traces_protocol' => env(Variables::OTEL_EXPORTER_OTLP_TRACES_PROTOCOL),
            'metrics_timeout' => (int) env(Variables::OTEL_EXPORTER_OTLP_METRICS_TIMEOUT, env(Variables::OTEL_EXPORTER_OTLP_TIMEOUT, 10000)),
            'metrics_headers' => (string) env(Variables::OTEL_EXPORTER_OTLP_METRICS_HEADERS, env(Variables::OTEL_EXPORTER_OTLP_HEADERS, '')),
            /**
             * Override protocol for metrics export
             */
            'metrics_protocol' => env(Variables::OTEL_EXPORTER_OTLP_METRICS_PROTOCOL),
            /**
             * Preferred metrics temporality
             * Supported values: "delta", "cumulative" (case-insensitive)
             */
            'metrics_temporality' => env(Variables::OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE),
            'logs_timeout' => (int) env(Variables::OTEL_EXPORTER_OTLP_LOGS_TIMEOUT, env(Variables::OTEL_EXPORTER_OTLP_TIMEOUT, 10000)),
            'logs_headers' => (string) env(Variables::OTEL_EXPORTER_OTLP_LOGS_HEADERS, env(Variables::OTEL_EXPORTER_OTLP_HEADERS, '')),
            /**
             * Override protocol for logs export
             */
            'logs_protocol' => env(Variables::OTEL_EXPORTER_OTLP_LOGS_PROTOCOL),
        ],

        'zipkin' => [
            'driver' => 'zipkin',
            'endpoint' => env(Variables::OTEL_EXPORTER_ZIPKIN_ENDPOINT, 'http://localhost:9411'),
            'timeout' => env(Variables::OTEL_EXPORTER_ZIPKIN_TIMEOUT, 10000),
            'max_retries' => (int) env('OTEL_EXPORTER_ZIPKIN_MAX_RETRIES', 3),
        ],
    ],

    /**
     * List of instrumentation used for application tracing
     */
    'instrumentation' => [
        Instrumentation\HttpServerInstrumentation::class => [
            'enabled' => env('OTEL_INSTRUMENTATION_HTTP_SERVER', true),
            'excluded_paths' => [],
            'excluded_methods' => [],
            'allowed_headers' => [],
            'sensitive_headers' => [],
            'sensitive_query_parameters' => [],
        ],

        Instrumentation\HttpClientInstrumentation::class => [
            'enabled' => env('OTEL_INSTRUMENTATION_HTTP_CLIENT', true),
            'manual' => false, // When set to true, you need to call `withTrace()` on the request to enable tracing
            'allowed_headers' => [],
            'sensitive_headers' => [],
            'sensitive_query_parameters' => [],
        ],

        Instrumentation\QueryInstrumentation::class => env('OTEL_INSTRUMENTATION_QUERY', true),

        Instrumentation\RedisInstrumentation::class => env('OTEL_INSTRUMENTATION_REDIS', true),

        Instrumentation\QueueInstrumentation::class => env('OTEL_INSTRUMENTATION_QUEUE', true),

        Instrumentation\CacheInstrumentation::class => env('OTEL_INSTRUMENTATION_CACHE', true),

        Instrumentation\EventInstrumentation::class => [
            'enabled' => env('OTEL_INSTRUMENTATION_EVENT', true),
            'excluded' => [],
        ],

        Instrumentation\ViewInstrumentation::class => env('OTEL_INSTRUMENTATION_VIEW', true),

        Instrumentation\LivewireInstrumentation::class => env('OTEL_INSTRUMENTATION_LIVEWIRE', true),

        Instrumentation\ConsoleInstrumentation::class => [
            'enabled' => env('OTEL_INSTRUMENTATION_CONSOLE', true),
            'commands' => [],
        ],

        Instrumentation\ScoutInstrumentation::class => env('OTEL_INSTRUMENTATION_SCOUT', true),

        Instrumentation\ReverbInstrumentation::class => env('OTEL_INSTRUMENTATION_REVERB', true),
    ],

    /**
     * Worker mode detection configuration
     *
     * Detects worker modes (e.g., Octane, Horizon, Queue) and optimizes OpenTelemetry
     * behavior for long-running processes.
     */
    'worker_mode' => [
        /**
         * Flush after each iteration (e.g. http request, queue job).
         * If false, flushes are batched and executed periodically and on shutdown.
         */
        'flush_after_each_iteration' => env('OTEL_WORKER_MODE_FLUSH_AFTER_EACH_ITERATION', false),

        /**
         * Flush interval in seconds.
         * When running in worker mode, all signals are exported at this interval.
         * Note: This setting is ignored if 'flush_after_each_iteration' is true.
         * Note: The interval is checked after each iteration, so the actual interval may be longer
         */
        'flush_interval' => (int) env('OTEL_WORKER_MODE_FLUSH_INTERVAL', env('OTEL_WORKER_MODE_COLLECT_INTERVAL', 60)),

        /**
         * Detectors to use for worker mode detection
         *
         * Every detector that returns true is registered.
         * Custom detectors implementing DetectorInterface can be added here.
         *
         * Built-in detectors:
         * - OctaneDetector: Detects Laravel Octane
         * - QueueDetector: Detects Laravel default queue worker and Laravel Horizon
         * - ReverbDetector: Detects Laravel Reverb (`reverb:start`)
         */
        'detectors' => [
            WorkerMode\Detectors\OctaneWorkerModeDetector::class,
            WorkerMode\Detectors\QueueWorkerModeDetector::class,
            WorkerMode\Detectors\ReverbWorkerModeDetector::class,
        ],
    ],
];
```

## User Context

When user context is enabled (`opentelemetry.user_context` config option, enabled by default),
the authenticated user id is automatically added as attribute `user.id` to all traces and logs.
This allows to easily correlate traces and logs with the user that generated them.

You can customize the user context attributes by providing a custom resolver in you service provider:

```php
use Keepsuit\LaravelOpenTelemetry\Facades\OpenTelemetry;
use Illuminate\Contracts\Auth\Authenticatable;

public function boot(): void
{
    OpenTelemetry::user(function (Authenticatable $user) {
        return [
            'user.id' => $user->getAuthIdentifier(),
            'user.email' => $user->email,
        ];
    });
}
```

## Instrumentations

This package provides a set of instrumentations to automatically trace common operations in a Laravel application.
Each instrumentation is configurable in `config/opentelemetry.php` and, when applicable, records default metrics described below.

### Http Server Requests

Http server requests are automatically traced by injecting `\Keepsuit\LaravelOpenTelemetry\Support\HttpServer\TraceRequestMiddleware::class` to the global middlewares.

Configuration options:

- `excluded_paths`: list of paths to exclude from tracing
- `excluded_methods`: list of HTTP methods to exclude from tracing
- `allowed_headers`: list of headers to include in the trace
- `sensitive_headers`: list of headers with sensitive data to hide in the trace

Metrics:

- `http.server.request.duration` (histogram, seconds) - Request processing time

You can disable this instrumentation by setting `OTEL_INSTRUMENTATION_HTTP_SERVER` to `false` or removing `HttpServerInstrumentation::class` from the config.

### Http Client

Http client requests are automatically traced by default, but you can set it to manual mode by setting `manual` to `true` in the config file.

When using manual mode, you need to call the `withTrace` method on the request builder to enable tracing for the request.

```php
Http::withTrace()->get('https://example.com');
```

The low-cardinality url template cannot be automatically detected in http client requests like in server requests. By default, the span name will be only the HTTP method (e.g. `GET`) but you can manually resolve the url template from the request.

In your service provider:

```php
use Keepsuit\LaravelOpenTelemetry\Instrumentation\HttpClientInstrumentation;
use Psr\Http\Message\RequestInterface;

public function boot(): void
{
    HttpClientInstrumentation::setRouteNameResolver(function (RequestInterface $request): ?string {
        return match (true) {
            str_starts_with($request->getUri()->getPath(), '/products/') => '/products/{id}',
            default => null,
        };
    });
}
```

Metrics:

- `http.client.request.duration` (histogram, seconds) - Outgoing HTTP request duration

You can disable this instrumentation by setting `OTEL_INSTRUMENTATION_HTTP_CLIENT` to `false` or removing `HttpClientInstrumentation::class` from the config.

### Http Methods

Http server and client instrumentations record methods outside the known list as `_OTHER` (span name `HTTP`), with the original method in `http.request.method_original`.
The known methods are the RFC 9110 methods, `PATCH` and `QUERY`.

### Database

Database queries are automatically traced. A span is created for each query executed.

Metrics:

- `db.client.operation.duration` (histogram, seconds) - Duration of database client operations

You can disable this instrumentation by setting `OTEL_INSTRUMENTATION_QUERY` to `false` or removing `QueryInstrumentation::class` from the config.

### Queue Jobs

Queue jobs are automatically traced. The instrumentation creates a parent span with kind `PRODUCER` when a job is dispatched and a child span with kind `CONSUMER` when the job is executed.

You can disable this instrumentation by setting `OTEL_INSTRUMENTATION_QUEUE` to `false` or removing `QueueInstrumentation::class` from the config.

### Redis

Redis commands are automatically traced. A span is created for each command executed.

Metrics:

- `db.client.operation.duration` (histogram, seconds) - Duration of Redis client operations

You can disable this instrumentation by setting `OTEL_INSTRUMENTATION_REDIS` to `false` or removing `RedisInstrumentation::class` from the config.

### Cache

Cache operations are recorded as events in the current active span.

You can disable this instrumentation by setting `OTEL_INSTRUMENTATION_CACHE` to `false` or removing `CacheInstrumentation::class` from the config.

### Events

Events are recorded as events in the current active span. Some internal Laravel events are excluded by default and can be customized in the configuration.

You can disable this instrumentation by setting `OTEL_INSTRUMENTATION_EVENT` to `false` or removing `EventInstrumentation::class` from the config.

### View

View rendering is automatically traced. A span is created for each rendered view.

You can disable this instrumentation by setting `OTEL_INSTRUMENTATION_VIEW` to `false` or removing `ViewInstrumentation::class` from the config.

### Livewire

Livewire components rendering is automatically traced. A span is created for each rendered component.

You can disable this instrumentation by setting `OTEL_INSTRUMENTATION_LIVEWIRE` to `false` or removing `LivewireInstrumentation::class` from the config.

### Console Commands

Console commands are not traced by default. You can trace console commands by adding them to the `commands` option of `ConsoleInstrumentation`.

You can disable this instrumentation by setting `OTEL_INSTRUMENTATION_CONSOLE` to `false` or removing `ConsoleInstrumentation::class` from the config.

You can add commands either by their signature or by their class name.

By signature:

```php
use Keepsuit\LaravelOpenTelemetry\Instrumentation;

return [
    // ...
    
    /**
     * List of instrumentation used for application tracing
     */
    'instrumentation' => [
        // ...
        
        Instrumentation\ConsoleInstrumentation::class => [
            'enabled' => env('OTEL_INSTRUMENTATION_CONSOLE', true),
            'commands' => [
                'app:example-command',
             ],
        ],
        
        // ...
    ],
    
    // ...
];
```

By class name:

```php
use Keepsuit\LaravelOpenTelemetry\Instrumentation;
use App\Console\Commands\ExampleCommand;

return [
    // ...
    
    /**
     * List of instrumentation used for application tracing
     */
    'instrumentation' => [
        // ...
        
        Instrumentation\ConsoleInstrumentation::class => [
            'enabled' => env('OTEL_INSTRUMENTATION_CONSOLE', true),
            'commands' => [
                ExampleCommand::class,
             ],
        ],
        
        // ...
    ],
    
    // ...
];
```

### Scout

Tracing of laravel scout operations requires the `opentelemetry` php extension to be installed and enabled.

The instrumentation trace operations performed by laravel scout in a generic way without tracking engine-specific attributes.

You can disable this instrumentation by setting `OTEL_INSTRUMENTATION_SCOUT` to `false` or removing `ScoutInstrumentation::class` from the config.

### Reverb

When running the Laravel Reverb server (`reverb:start`), the following metrics are recorded:

| Metric                              | Instrument      | Unit           | Attributes                                      |
|-------------------------------------|-----------------|----------------|-------------------------------------------------|
| `laravel.reverb.messages`           | Counter         | `{message}`    | `laravel.reverb.app.id`, `network.io.direction` |
| `laravel.reverb.active_connections` | ObservableGauge | `{connection}` | `laravel.reverb.app.id`                         |

`network.io.direction` is `receive` for messages received from clients and `transmit` for messages sent to clients (one per recipient, including protocol messages like `pusher:pong`).
`laravel.reverb.active_connections` requires Reverb 1.14 or later and is not recorded on older versions.

Each Reverb process counts only its own connections and messages: when scaling Reverb horizontally, sum the series of all instances to get the totals.

Metrics are exported periodically by the `ReverbWorkerModeDetector` (see [Worker Mode](#worker-mode)).
If you have already published the config file, add both `ReverbInstrumentation::class` to `instrumentation` and `ReverbWorkerModeDetector::class` to `worker_mode.detectors`.

Do not add `reverb:start` to the `commands` option of `ConsoleInstrumentation`: the command runs for the whole life of the server, so its span would never end.

> [!WARNING]
> Exports run synchronously inside the Reverb event loop: while an export is in progress, no WebSocket message is handled.
> If the collector is slow or unreachable, the export (with its retries) can block the server long enough for clients to time out.
> For the Reverb process, export to a local collector (e.g. a sidecar) and keep timeouts and retries low (see [Export Failures](#export-failures)).

You can disable this instrumentation by setting `OTEL_INSTRUMENTATION_REVERB` to `false` or removing `ReverbInstrumentation::class` from the config.

## Traces

This package provides tracing capabilities and utilities that integrate with the instrumentations described above.

### Manual Traces

Spans can be manually created with the `newSpan` method on the `Tracer` facade.
This method returns a `SpanBuilder` instance that can be used to customize and start the span.

The simplest way to create a custom trace is with `measure` method:

```php
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;

Tracer::newSpan('my custom trace')->measure(function () {
    // do something
});
```

Alternatively you can manage the span manually:

```php
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;

$span = Tracer::newSpan('my custom trace')->start();

// do something

$span->end();
```

With `measure` the span is automatically set to active (so it will be used as parent for new spans).
With `start` you have to manually set the span as active:

```php
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;

$span = Tracer::newSpan('my custom trace')->start();
$scope = $span->activate()

// do something

$scope->detach();
$span->end();
```

Other utility methods are available on the `Tracer` facade:

```php
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;

Tracer::traceId(); // get the active trace id
Tracer::activeSpan(); // get the active span
Tracer::activeScope(); // get the active scope
Tracer::currentContext(); // get the current trace context (useful for advanced use cases)
Tracer::propagationHeaders(); // get the propagation headers required to propagate the trace to other services
Tracer::extractContextFromPropagationHeaders(array $headers); // extract the trace context from propagation headers
```

### Trace Sampling

Sampling is the process of selecting which traces to collect and export.
Since tracing every single request can be expensive at scale, sampling allows you to reduce costs while still maintaining visibility into your application's behavior.

This package supports two types of sampling that work together:

| Aspect                 | Head Sampling                      | Tail Sampling                           |
|------------------------|------------------------------------|-----------------------------------------|
| **Decision time**      | At span start                      | At trace end                            |
| **Criteria available** | Trace ID, parent context           | Full trace data, errors, duration       |
| **Use case**           | Rate limiting, percentage sampling | Error traces, slow traces, custom rules |
| **Multi-service**      | Works per-service                  | Must use Collector                      |

#### Head Sampling

Head sampling makes decisions at the beginning of a trace, based on the trace ID and parent context. This is fast and works well for:

- Percentage-based sampling (e.g., keep 5% of traces)
- Parent-based sampling (keep traces based on whether parent was sampled)
- Rate limiting

Head sampling is configured in the `traces.sampler` section of the config file.

#### Tail Sampling

Tail sampling makes decisions after a trace has completed, allowing you to keep only "interesting" traces while discarding the rest. For example, you can:

- Keep traces that contain errors
- Keep traces that exceed a duration threshold
- Define custom sampling rules based on span attributes
- Fall back to ratio sampling for other traces

> [!NOTE]
> Tail sampling implemented at the application-level should only be used for single-service scenarios.
> For multi-service tail sampling, use the OpenTelemetry Collector instead because it has visibility into the complete trace across all services.

When tail sampling is enabled, it waits for the trace to complete (or a timeout defined by the `decision_wait` config) before making a sampling decision based on the entire trace.
It evaluates the trace against a set of rules in the order they appear in the configuration, and the first rule that returns `Keep` or `Drop` determines the outcome.
If none of the rules make a decision, the configured head sampler is used. (We suggest using the `traceidratio` sampler as fallback).

By default, two tail sampling rules are included:

- Errors Rule: keeps traces with any span that has an error status
- Slow Trace Rule: keeps traces that exceed a duration threshold (default 2000ms)

Tail sampling can be configured with these environment variables (or editing the config file directly):

| Variable                                             | Description                                                                          | Default |
|------------------------------------------------------|--------------------------------------------------------------------------------------|---------|
| `OTEL_TRACES_TAIL_SAMPLING_ENABLED`                  | Enable tail sampling                                                                 | `false` |
| `OTEL_TRACES_TAIL_SAMPLING_DECISION_WAIT`            | Maximum time to wait for trace completion before making a decision (in milliseconds) | `5000`  |
| `OTEL_TRACES_TAIL_SAMPLING_RULE_KEEP_ERRORS`         | Enable the built-in Errors Rule                                                      | `true`  |
| `OTEL_TRACES_TAIL_SAMPLING_RULE_SLOW_TRACES`         | Enable the built-in Slow Trace Rule                                                  | `true`  |
| `OTEL_TRACES_TAIL_SAMPLING_SLOW_TRACES_THRESHOLD_MS` | Duration threshold for the Slow Trace Rule (in milliseconds)                         | `2000`  |

#### Custom Rules

You can create custom tail sampling rules by implementing the `TailSamplingRuleInterface`:

```php
use Keepsuit\LaravelOpenTelemetry\TailSampling\TailSamplingRuleInterface;
use Keepsuit\LaravelOpenTelemetry\TailSampling\SamplingResult;
use Keepsuit\LaravelOpenTelemetry\TailSampling\TraceBuffer;

class MyCustomRule implements TailSamplingRuleInterface
{
    public function initialize(array $options): void
    {
        // Configure the rule using options from config
    }

    public function evaluate(TraceBuffer $trace): SamplingResult
    {
        // Evaluate the trace and return a SamplingResult
        // Return SamplingResult::Keep to keep the trace
        // Return SamplingResult::Drop to drop the trace
        // Return SamplingResult::Forward to let the next rule decide
    }
}
```

Then register your custom rule in the configuration:

```php
// config/opentelemetry.php
'tail_sampling' => [
    'enabled' => true,
    'rules' => [
        MyCustomRule::class => [...], // Your rule configuration
    ],
],
```

### Logs Context

When starting a trace with provided instrumentation, the trace id is automatically injected in the log context.
This allows to correlate logs with traces.

If you are starting the root trace manually,
you should call `Tracer::updateLogContext()` to inject the trace id in the log context.

> [!NOTE]
> When using the OpenTelemetry logs driver (`otlp`),
> the trace id is automatically injected in the log context without the need to call `Tracer::updateLogContext()`.

## Metrics

The Meter facade provide methods to create metric instruments such as counters, gauges, and histograms.

The supported instruments are:

- Counter
- ObservableCounter
- UpDownCounter
- ObservableUpDownCounter
- Gauge
- ObservableGauge
- Histogram

There is also a `batchObserve` method to record multiple measurements at once.

> [!NOTE]
> Instruments are cached by name to prevent duplicate instrument creation in the same Meter instance.

Example usage:

```php
use Keepsuit\LaravelOpenTelemetry\Facades\Meter;

// create or retrieve a counter instrument
$counter = Meter::counter('my-meter', 'times', 'my custom meter');
$counter->add(1);

// create or retrieve a histogram instrument
$histogram = Meter::histogram('my-histogram', 'ms', 'my custom histogram');
$histogram->record(100, ['name' => 'value', 'app' => 'my-app']);

// create or retrieve a gauge instrument
$gauge = Meter::gauge('my-gauge', null, 'my custom gauge');
$gauge->record(100, ['name' => 'value', 'app' => 'my-app']);
$gauge->record(1.2, ['name' => 'percentage', 'app' => 'my-app']);

// Execute the callback with multiple observable instruments
Meter::batchObserve([
    Meter::observableCounter('usage', description: 'count of items used'),
    Meter::observableGauge('pressure', description: 'force per unit area'),
], function(ObserverInterface $usageObserver, ObserverInterface $pressureObserver): void {
    [$usage, $pressure] = expensive_system_call();
    $usageObserver->observe($usage);
    $pressureObserver->observe($pressure);
});
```

### Metrics Temporality

The OTLP exporter supports setting a preferred temporality for exported metrics with the `OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE` env variable.
The supported values are `delta` and `cumulative` (case-insensitive). Other values are ignored.
If not set, the exporter and SDK defaults apply.

### Metrics in per-request runtimes

In per-request runtimes (PHP-FPM, FrankenPHP without worker mode) every request boots a new meter provider and exports its own metrics when it ends. With Cumulative temporality the result depends on `service.instance.id`:

- **Shared id** (e.g. the hostname): every request overwrites the same series with its own counts, so counters never exceed a single request's count and `rate()`/`increase()` return 0.
- **Random id** (the SDK default): every request creates a new series, which leads to unbounded cardinality.

Use Delta temporality instead (`OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE=delta`) with a stable `service.instance.id` per host or pod, and convert to cumulative in the collector with the `deltatocumulative` processor.
Concurrent requests aggregate correctly: the stream start time is fixed by the first sample and later deltas are summed.

All deltas of a stream must reach the **same** collector instance. Several replicas behind a load-balanced service each keep their own state and emit conflicting series. Use a node-local agent (e.g. a DaemonSet with `internalTrafficPolicy: Local`), a sidecar, or the `loadbalancing` exporter routing by `service.instance.id`.

Both examples below receive OTLP over HTTP, convert deltas to cumulative and export to the Prometheus OTLP receiver (`--web.enable-otlp-receiver`).

With [Grafana Alloy](https://grafana.com/docs/alloy/latest/reference/components/otelcol/otelcol.processor.deltatocumulative/), `otelcol.processor.deltatocumulative` is experimental and requires `--stability.level=experimental`:

```alloy
otelcol.receiver.otlp "default" {
  http {
    endpoint = "0.0.0.0:4318"
  }

  output {
    metrics = [otelcol.processor.deltatocumulative.default.input]
  }
}

otelcol.processor.deltatocumulative "default" {
  max_stale = "5m"

  output {
    metrics = [otelcol.processor.batch.default.input]
  }
}

otelcol.processor.batch "default" {
  output {
    metrics = [otelcol.exporter.otlphttp.prometheus.input]
  }
}

otelcol.exporter.otlphttp "prometheus" {
  client {
    endpoint = "http://prometheus:9090/api/v1/otlp"
  }
}
```

With the [OpenTelemetry Collector](https://github.com/open-telemetry/opentelemetry-collector-contrib/tree/main/processor/deltatocumulativeprocessor), the processor is available in the contrib distribution:

```yaml
receivers:
  otlp:
    protocols:
      http:
        endpoint: 0.0.0.0:4318

processors:
  deltatocumulative:
    max_stale: 5m
  batch: {}

exporters:
  otlphttp/prometheus:
    endpoint: http://prometheus:9090/api/v1/otlp

service:
  pipelines:
    metrics:
      receivers: [otlp]
      processors: [deltatocumulative, batch]
      exporters: [otlphttp/prometheus]
```

Known limits:

- deltas that reach the collector out of order are dropped;
- after `max_stale` (default 5m) without samples the stream restarts from zero, which backends treat as a counter reset;
- backends that ingest Delta natively need no conversion.

Long-running processes (queue workers, Horizon, Octane or FrankenPHP worker mode) export once per `worker_mode.flush_interval`.
They work with either temporality when `service.instance.id` is unique per process; with Delta, processes sharing an id (e.g. several workers in the same pod) are summed by the collector too.
Keep `worker_mode.flush_after_each_iteration` disabled with Delta: exporting after every job or request brings back the out-of-order drops under load.

## Logs

This package provides a custom log channel that allows to process logs with OpenTelemetry instrumentation.
This package injects a log channel named `otlp` that can be used to send logs to OpenTelemetry using laravel default log system.

```php
// config/logging.php
'channels' => [
    // injected channel config, you can override it adding an `otlp` channel in your config
    'otlp' => [
        'driver' => 'monolog',
        'handler' => \Keepsuit\LaravelOpenTelemetry\Support\OpenTelemetryMonologHandler::class,
        'level' => env('LOG_LEVEL', 'debug'),
    ]
]
```

The injected channel takes its level from `opentelemetry.logs.level`, which defaults to `LOG_LEVEL`.
If you published the config file before this option existed, add `'level' => env('LOG_LEVEL', 'debug')` to its `logs` section.

Data added with Laravel's `Context` facade (`Context::add()`) is exported as log record attributes, together with the log context.
When the same key is present in both, the log context wins.
Hidden context (`Context::addHidden()`) is not exported.

As an alternative, you can use the `Logger` facade to send logs directly to OpenTelemetry:

```php
use Keepsuit\LaravelOpenTelemetry\Facades\Logger;

Logger::emergency('my log message');
Logger::alert('my log message');
Logger::critical('my log message');
Logger::error('my log message');
Logger::warning('my log message');
Logger::notice('my log message');
Logger::info('my log message');
Logger::debug('my log message');
```

## Worker Mode

When Laravel is running in worker mode (e.g., Octane, Horizon, Queue workers), the application runs as a long-lived process that handles multiple requests or jobs in a single process lifecycle.
By default, exports are batched and flushed periodically or on process shutdown.
The `worker_mode.flush_after_each_iteration` config option allows to flush telemetry at the end of each iteration.

Worker mode is automatically detected using built-in detectors (for Laravel octane, horizon, queue workers and reverb), but you can also implement custom detectors for other runtimes.

The Reverb server has no requests or jobs: there an iteration is a timer on the event loop that fires every second, and the flush interval applies as for other workers.

Worker mode can be configured with these environment variables (or editing the config file directly):

| Variable                                      | Description                                            | Default |
|-----------------------------------------------|--------------------------------------------------------|---------|
| `OTEL_WORKER_MODE_FLUSH_AFTER_EACH_ITERATION` | Enable per-iteration flushing                          | `false` |
| `OTEL_WORKER_MODE_FLUSH_INTERVAL`             | Flush interval in seconds for worker mode              | `60`    |

The previous names, `OTEL_WORKER_MODE_COLLECT_INTERVAL` and the `worker_mode.metrics_collect_interval` config key, are still read when the new ones are not set.

Otherwise, after an iteration, if the interval has elapsed, metrics are collected and pending traces, metrics and logs are exported.
If `OTEL_WORKER_MODE_FLUSH_AFTER_EACH_ITERATION` is `true`, the per-iteration flush behavior is used and the periodic interval is ignored.

## Export Failures

Exports are synchronous: the request, job or command that triggers an export waits for it to finish.
Every PHP process also exports its pending metrics when it shuts down, short Artisan commands included (for example the ones a container runs before starting the application).

When the collector is not available, each export attempt costs:

- connection refused (nothing listening on the endpoint): a few milliseconds;
- unreachable host (packets dropped): up to `OTEL_EXPORTER_OTLP_CONNECT_TIMEOUT`;
- the collector accepts the connection but does not answer: up to `OTEL_EXPORTER_OTLP_TIMEOUT`.

An export makes `1 + OTEL_EXPORTER_OTLP_MAX_RETRIES` attempts.

| Variable                             | Description                                                                                   | Default |
|--------------------------------------|-----------------------------------------------------------------------------------------------|---------|
| `OTEL_EXPORTER_OTLP_TIMEOUT`         | Export timeout in milliseconds (also per signal, e.g. `OTEL_EXPORTER_OTLP_TRACES_TIMEOUT`)    | `10000` |
| `OTEL_EXPORTER_OTLP_CONNECT_TIMEOUT` | Connection timeout in milliseconds, for http protocols when Guzzle is installed               | `1000`  |
| `OTEL_EXPORTER_OTLP_MAX_RETRIES`     | Retries after a failed export                                                                 | `1`     |

`OTEL_EXPORTER_OTLP_CONNECT_TIMEOUT` and `OTEL_EXPORTER_OTLP_MAX_RETRIES` are options of this package, not OpenTelemetry standard variables.
With gRPC the timeout covers the whole call, connection included.

Retries default to 1, while the OpenTelemetry PHP SDK uses 3.
The OTLP specification asks to retry transient errors but does not set a number of attempts, and with synchronous exports every retry adds a full wait to the request, job or command.
Losing an export is cheaper: with cumulative temporality the next metrics export carries the totals, so only one data point is missing; spans and log records of the failed batch are lost.

When the collector runs close to the application (same host or cluster), lower both timeouts, for example `OTEL_EXPORTER_OTLP_CONNECT_TIMEOUT=250` and `OTEL_EXPORTER_OTLP_TIMEOUT=2000`.

## Development Setup

To simplify development, a `Makefile` is provided. The project runs in a Docker container that mirrors your host user's UID and GID to avoid permission issues.

### Available Makefile Commands

| Command      | Description                                                                |
|--------------|----------------------------------------------------------------------------|
| `make build` | Builds the Docker image with your UID/GID for proper file permissions.     |
| `make start` | Starts the containers in the background using Docker Compose.              |
| `make stop`  | Stops and removes the containers.                                          |
| `make shell` | Starts the containers (if needed) and opens a Bash shell in the `app` one. |
| `make test`  | Runs the test suite via Composer inside the `app` container.               |
| `make lint`  | Runs the linter via Composer inside the `app` container.                   |

> 📝 Before using `make shell`, ensure the container is running (`make start` in another terminal).

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Fabio Capucci](https://github.com/keepsuit)
- [Aurimas Niekis](https://github.com/aurimasniekis)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
