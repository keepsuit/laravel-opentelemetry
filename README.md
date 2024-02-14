# OpenTelemetry integration for laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/keepsuit/laravel-opentelemetry.svg?style=flat-square)](https://packagist.org/packages/keepsuit/laravel-opentelemetry)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/keepsuit/laravel-opentelemetry/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/keepsuit/laravel-opentelemetry/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/keepsuit/laravel-opentelemetry/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/keepsuit/laravel-opentelemetry/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/keepsuit/laravel-opentelemetry.svg?style=flat-square)](https://packagist.org/packages/keepsuit/laravel-opentelemetry)

_OpenTelemetry is a collection of tools, APIs, and SDKs. Use it to instrument, generate, collect, and export telemetry data (metrics, logs, and traces) to help you analyze your software’s performance and behavior._

This package allow to integrate OpenTelemetry in a Laravel application.
Right now only tracing is available.

## Provided tracing integrations

- [Http server requests](#http-server-requests)
- [Http client](#http-client)
- [Database](#database)
- [Redis](#redis)
- [Queue jobs](#redis)
- [Logs context](#logs-context)
- [Manual traces](#manual-traces)

## Installation

You can install the package via composer:

```bash
composer require keepsuit/laravel-opentelemetry
```

You can publish the config file with:

```bash
php artisan vendor:publish --provider="Keepsuit\LaravelOpentelemetry\LaravelOpentelemetryServiceProvider" --tag="opentelemetry-config"
```

This is the contents of the published config file:

```php
use Keepsuit\LaravelOpenTelemetry\Instrumentation;

return [
    /**
     * Service name
     */
    'service_name' => env('OTEL_SERVICE_NAME', \Illuminate\Support\Str::slug(env('APP_NAME', 'laravel-app'))),

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
     * Traces exporter
     * Supported: "zipkin", "http", "grpc", "console", "null"
     */
    'exporter' => env('OTEL_TRACES_EXPORTER', 'http'),

    /**
     * Comma separated list of propagators to use.
     * Supports any otel propagator, for example: "tracecontext", "baggage", "b3", "b3multi", "none"
     */
    'propagators' => env('OTEL_PROPAGATORS', 'tracecontext'),

    /**
     * List of instrumentation used for application tracing
     */
    'instrumentation' => [
        Instrumentation\HttpServerInstrumentation::class => [
            'enabled' => env('OTEL_INSTRUMENTATION_HTTP_SERVER', true),
            'excluded_paths' => [],
            'allowed_headers' => [],
            'sensitive_headers' => [],
        ],

        Instrumentation\HttpClientInstrumentation::class => [
            'enabled' => env('OTEL_INSTRUMENTATION_HTTP_CLIENT', true),
            'allowed_headers' => [],
            'sensitive_headers' => [],
        ],

        Instrumentation\QueryInstrumentation::class => env('OTEL_INSTRUMENTATION_QUERY', true),

        Instrumentation\RedisInstrumentation::class => env('OTEL_INSTRUMENTATION_REDIS', true),

        Instrumentation\QueueInstrumentation::class => env('OTEL_INSTRUMENTATION_QUEUE', true),

        Instrumentation\CacheInstrumentation::class => env('OTEL_INSTRUMENTATION_CACHE', true),

        Instrumentation\EventInstrumentation::class => [
            'enabled' => env('OTEL_INSTRUMENTATION_EVENT', true),
            'ignored' => [],
        ],
    ],

    /**
     * Exporters config
     */
    'exporters' => [
        'otlp' => [
            'endpoint' => env('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT', env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318')),

            // Supported: "grpc", "http/protobuf", "http/json"
            'protocol' => env('OTEL_EXPORTER_OTLP_TRACES_PROTOCOL', env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http/protobuf')),
        ],

        'zipkin' => [
            'endpoint' => env('OTEL_EXPORTER_ZIPKIN_ENDPOINT', 'http://localhost:9411'),
        ],
    ],

    /**
     * Logs context config
     */
    'logs' => [
        /**
         * Inject active trace id in log context
         */
        'inject_trace_id' => true,

        /**
         * Context field name for trace id
         */
        'trace_id_field' => 'traceId',
    ],
];
```

## Usage

### Http server requests

Http server requests are automatically traced by injecting `\Keepsuit\LaravelOpenTelemetry\Support\HttpServer\TraceRequestMiddleware::class` to the global middlewares.
You can disable it by setting `OT_INSTRUMENTATION_HTTP_SERVER` to `false` or removing the `HttpServerInstrumentation::class` from the config file.

Configuration options:

- `excluded_paths`: list of paths to exclude from tracing
- `allowed_headers`: list of headers to include in the trace
- `sensitive_headers`: list of headers with sensitive data to hide in the trace

### Http client

To trace an outgoing http request call the `withTrace` method on the request builder.

```php
Http::withTrace()->get('https://example.com');
```

You can disable it by setting `OT_INSTRUMENTATION_HTTP_CLIENT` to `false` or removing the `HttpClientInstrumentation::class` from the config file.

Configuration options:

- `allowed_headers`: list of headers to include in the trace
- `sensitive_headers`: list of headers with sensitive data to hide in the trace

### Database

Database queries are automatically traced.
You can disable it by setting `OT_INSTRUMENTATION_QUERY` to `false` or removing the `QueryInstrumentation::class` from the config file.

### Redis

Redis commands are automatically traced.
You can disable it by setting `OT_INSTRUMENTATION_REDIS` to `false` or removing the `RedisInstrumentation::class` from the config file.

### Queue jobs

Queue jobs are automatically traced.
It will automatically create a parent span with kind `PRODUCER` when a job is dispatched and a child span with kind `CONSUMER` when the job is executed.
You can disable it by setting `OT_INSTRUMENTATION_QUEUE` to `false` or removing the `QueueInstrumentation::class` from the config file.

### Logs context

When starting a trace with provided instrumentation, the trace id is automatically injected in the log context.
This allows to correlate logs with traces.

If you are starting the root trace manually,
you should call `Tracer::updateLogContext()` to inject the trace id in the log context.

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

Tracer::isRecording(); // check if tracing is enabled
Tracer::traceId(); // get the active trace id
Tracer::activeSpan(); // get the active span
Tracer::activeScope(); // get the active scope
Tracer::currentContext(); // get the current trace context (useful for advanced use cases)
Tracer::propagationHeaders(); // get the propagation headers required to propagate the trace to other services
Tracer::extractContextFromPropagationHeaders(array $headers); // extract the trace context from propagation headers
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Fabio Capucci](https://github.com/keepsuit)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
