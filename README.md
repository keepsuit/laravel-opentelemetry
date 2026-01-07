# OpenTelemetry integration for laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/keepsuit/laravel-opentelemetry.svg?style=flat-square)](https://packagist.org/packages/keepsuit/laravel-opentelemetry)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/keepsuit/laravel-opentelemetry/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/keepsuit/laravel-opentelemetry/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/keepsuit/laravel-opentelemetry/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/keepsuit/laravel-opentelemetry/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/keepsuit/laravel-opentelemetry.svg?style=flat-square)](https://packagist.org/packages/keepsuit/laravel-opentelemetry)

_OpenTelemetry is a collection of tools, APIs, and SDKs. Use it to instrument, generate, collect, and export telemetry data (metrics, logs, and traces) to help you analyze your software’s performance and behavior._

This package allow to integrate OpenTelemetry in a Laravel application.

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
use Keepsuit\LaravelOpenTelemetry\Instrumentation;
use Keepsuit\LaravelOpenTelemetry\Support\ResourceAttributesParser;
use OpenTelemetry\SDK\Common\Configuration\Variables;

return [
    /**
     * Service name
     */
    'service_name' => env(Variables::OTEL_SERVICE_NAME, \Illuminate\Support\Str::slug((string) env('APP_NAME', 'laravel-app'))),

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
         * Supported drivers: "otlp", "console", "null"
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
         * Supported drivers: "otlp", "console", "null"
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
     * Supported drivers: "otlp", "zipkin", "console", "null"
     */
    'exporters' => [
        'otlp' => [
            'driver' => 'otlp',
            'endpoint' => env(Variables::OTEL_EXPORTER_OTLP_ENDPOINT, 'http://localhost:4318'),
            /**
             * Supported protocols: "grpc", "http/protobuf", "http/json"
             */
            'protocol' => env(Variables::OTEL_EXPORTER_OTLP_PROTOCOL, 'http/protobuf'),
            'max_retries' => env('OTEL_EXPORTER_OTLP_MAX_RETRIES', 3),
            'traces_timeout' => env(Variables::OTEL_EXPORTER_OTLP_TRACES_TIMEOUT, env(Variables::OTEL_EXPORTER_OTLP_TIMEOUT, 10000)),
            'traces_headers' => (string) env(Variables::OTEL_EXPORTER_OTLP_TRACES_HEADERS, env(Variables::OTEL_EXPORTER_OTLP_HEADERS, '')),
            /**
             * Override protocol for traces export
             */
            'traces_protocol' => env(Variables::OTEL_EXPORTER_OTLP_TRACES_PROTOCOL),
            'metrics_timeout' => env(Variables::OTEL_EXPORTER_OTLP_METRICS_TIMEOUT, env(Variables::OTEL_EXPORTER_OTLP_TIMEOUT, 10000)),
            'metrics_headers' => (string) env(Variables::OTEL_EXPORTER_OTLP_METRICS_HEADERS, env(Variables::OTEL_EXPORTER_OTLP_HEADERS, '')),
            /**
             * Override protocol for metrics export
             */
            'metrics_protocol' => env(Variables::OTEL_EXPORTER_OTLP_METRICS_PROTOCOL),
            /**
             * Preferred metrics temporality
             * Supported values: "Delta", "Cumulative"
             */
            'metrics_temporality' => env(Variables::OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE),
            'logs_timeout' => env(Variables::OTEL_EXPORTER_OTLP_LOGS_TIMEOUT, env(Variables::OTEL_EXPORTER_OTLP_TIMEOUT, 10000)),
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
            'max_retries' => env('OTEL_EXPORTER_ZIPKIN_MAX_RETRIES', 3),
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
        ],

        Instrumentation\HttpClientInstrumentation::class => [
            'enabled' => env('OTEL_INSTRUMENTATION_HTTP_CLIENT', true),
            'manual' => false, // When set to true, you need to call `withTrace()` on the request to enable tracing
            'allowed_headers' => [],
            'sensitive_headers' => [],
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
    ],
];
```

> [!NOTE]  
> OpenTelemetry instrumentation can be completely disabled by setting the `OTEL_SDK_DISABLED` environment variable to `true`.

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

## Traces

This package provides a set of integrations to automatically trace common operations in a Laravel application.
You can disable or customize each integration in the config file in the `instrumentations` section.

### Provided tracing integrations

- [Http server requests](#http-server-requests)
- [Http client](#http-client)
- [Database](#database)
- [Redis](#redis)
- [Queue jobs](#queue-jobs)
- [Cache](#cache)
- [Events](#events)
- [View](#view)
- [Livewire](#livewire)
- [Console commands](#console-commands)
- [Logs context](#logs-context)
- [Manual traces](#manual-traces)

### Http server requests

Http server requests are automatically traced by injecting `\Keepsuit\LaravelOpenTelemetry\Support\HttpServer\TraceRequestMiddleware::class` to the global middlewares.
You can disable it by setting `OT_INSTRUMENTATION_HTTP_SERVER` to `false` or removing the `HttpServerInstrumentation::class` from the config file.

Configuration options:

- `excluded_paths`: list of paths to exclude from tracing
- `excluded_methods`: list of HTTP methods to exclude from tracing
- `allowed_headers`: list of headers to include in the trace
- `sensitive_headers`: list of headers with sensitive data to hide in the trace

### Http client

Http client requests are automatically traced by default, but you can set it to manual mode by setting `manual` to `true` in the config file.

When using manual mode, you need to call the `withTrace` method on the request builder to enable tracing for the request.

```php
Http::withTrace()->get('https://example.com');
```

The low-cardinality url template cannot be automatically detected in http client requests like in server requests.
By default, the span name will be only the HTTP method (e.g. `GET`) but you can set manually resolve the url template from the request.

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

You can disable it by setting `OT_INSTRUMENTATION_HTTP_CLIENT` to `false` or removing the `HttpClientInstrumentation::class` from the config file.

Configuration options:

- `manual`: set to `true` to enable manual tracing
- `allowed_headers`: list of headers to include in the trace
- `sensitive_headers`: list of headers with sensitive data to hide in the trace

### Database

Database queries are automatically traced. A span is created for each query executed.

You can disable it by setting `OT_INSTRUMENTATION_QUERY` to `false` or removing the `QueryInstrumentation::class` from the config file.

### Redis

Redis commands are automatically traced. A span is created for each command executed.

You can disable it by setting `OT_INSTRUMENTATION_REDIS` to `false` or removing the `RedisInstrumentation::class` from the config file.

### Queue jobs

Queue jobs are automatically traced.
It will automatically create a parent span with kind `PRODUCER` when a job is dispatched and a child span with kind `CONSUMER` when the job is executed.

You can disable it by setting `OT_INSTRUMENTATION_QUEUE` to `false` or removing the `QueueInstrumentation::class` from the config file.

### Cache

Cache operations are recorded as events in the current active span.

You can disable it by setting `OT_INSTRUMENTATION_CACHE` to `false` or removing the `CacheInstrumentation::class` from the config file.

### Events

Events are recorded as events in the current active span. Some internal laravel events are excluded by default.
You can customize the excluded events in the config file.

You can disable it by setting `OT_INSTRUMENTATION_EVENT` to `false` or removing the `EventInstrumentation::class` from the config file.

### View

View rendering is automatically traced. A span is created for each rendered view.

You can disable it by setting `OT_INSTRUMENTATION_VIEW` to `false` or removing the `ViewInstrumentation::class` from the config file.

### Livewire

Livewire components rendering is automatically traced. A span is created for each rendered component.

You can disable it by setting `OT_INSTRUMENTATION_LIVEWIRE` to `false` or removing the `LivewireInstrumentation::class` from the config file.

### Console commands

Console commands are not traced by default.
You can trace console commands by adding them to the `commands` option of `ConsoleInstrumentation`.

You can disable it by setting `OT_INSTRUMENTATION_CONSOLE` to `false` or removing the `ConsoleInstrumentation::class` from the config file.

Configuration options:

- `commands`: list of commands to trace

### Logs context

When starting a trace with provided instrumentation, the trace id is automatically injected in the log context.
This allows to correlate logs with traces.

If you are starting the root trace manually,
you should call `Tracer::updateLogContext()` to inject the trace id in the log context.

> [!NOTE]
> When using the OpenTelemetry logs driver (`otlp`),
> the trace id is automatically injected in the log context without the need to call `Tracer::updateLogContext()`.

### Manual traces

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

## Metrics

You can create custom meters using the `Meter` facade:

```php
use Keepsuit\LaravelOpenTelemetry\Facades\Meter;

// create a counter meter
$meter = Meter::createCounter('my-meter', 'times', 'my custom meter');
$meter->add(1);

// create a histogram meter
$meter = Meter::createHistogram('my-histogram', 'ms', 'my custom histogram');
$meter->record(100, ['name' => 'value', 'app' => 'my-app']);

// create a gauge meter
$meter = Meter::createGauge('my-gauge', null, 'my custom gauge');
$meter->record(100, ['name' => 'value', 'app' => 'my-app']);
$meter->record(1.2, ['name' => 'percentage', 'app' => 'my-app']);
```

### Metrics Temporality

The OTLP exporter supports setting a preferred temporality for exported metrics with `OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE` env variable.
The supported values are `Delta` and `Cumulative`.
If not set, the default temporality for each metric type will be used.

## Logs

This package provides a custom log channel that allows to process logs with OpenTelemetry instrumentation.
This packages injects a log channel named `otlp` that can be used to send logs to OpenTelemetry using laravel default log system.

```php
// config/logging.php
'channels' => [
    // injected channel config, you can override it adding an `otlp` channel in your config
    'otlp' => [
        'driver' => 'monolog',
        'handler' => \Keepsuit\LaravelOpenTelemetry\Support\OpenTelemetryMonologHandler::class,
        'level' => 'debug',
    ]
]
```

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

### Development Setup

To simplify development, a `Makefile` is provided. The project runs in a Docker container that mirrors your host user's UID and GID to avoid permission issues.

#### Available Makefile Commands

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
