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
<?php

use Keepsuit\LaravelOpenTelemetry\Instrumentation;

return [
    /**
     * Service name
     */
    'service_name' => env('OTEL_SERVICE_NAME', \Illuminate\Support\Str::slug(env('APP_NAME', 'laravel-app'))),

    /**
     * Comma separated list of propagators to use.
     * Supports any otel propagator, for example: "tracecontext", "baggage", "b3", "b3multi", "none"
     */
    'propagators' => env('OTEL_PROPAGATORS', 'tracecontext'),

    /**
     * OpenTelemetry Traces configuration
     */
    'traces' => [
        /**
         * Traces exporter
         * This should be the key of one of the exporters defined in the exporters section
         */
        'exporter' => env('OTEL_TRACES_EXPORTER', 'otlp'),

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
        'exporter' => env('OTEL_LOGS_EXPORTER', 'otlp'),

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
        'trace_id_field' => 'traceid',
    ],

    /**
     * OpenTelemetry exporters
     *
     * Here you can configure exports used by traces and logs.
     * If you want to use the same protocol with different endpoints,
     * you can copy the exporter with a different and change the endpoint
     *
     * Supported drivers: "otlp", "zipkin", "console", "null"
     */
    'exporters' => [
        'otlp' => [
            'driver' => 'otlp',
            'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318'),
            // Supported: "grpc", "http/protobuf", "http/json"
            'protocol' => env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http/protobuf'),
            'timeout' => env('OTEL_EXPORTER_OTLP_TIMEOUT', 10000),
            'traces_timeout' => env('OTEL_EXPORTER_OTLP_TRACES_TIMEOUT', 10000),
            'metrics_timeout' => env('OTEL_EXPORTER_OTLP_METRICS_TIMEOUT', 10000),
            'logs_timeout' => env('OTEL_EXPORTER_OTLP_LOGS_TIMEOUT', 10000),
            'traces_max_retries' => env('OTEL_EXPORTER_OTLP_TRACES_MAX_RETRIES', 3),
        ],

        'zipkin' => [
            'driver' => 'zipkin',
            'endpoint' => env('OTEL_EXPORTER_ZIPKIN_ENDPOINT', 'http://localhost:9411'),
            'timeout' => env('OTEL_EXPORTER_ZIPKIN_TIMEOUT', 10000),
        ],
    ],

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
];
```

## Traces

This package provides a set of integrations to automatically trace common operations in a Laravel application.
You can disable or customize each integration in the config file in the `instrumentations` section.

### Provided tracing integrations

- [Http server requests](#http-server-requests)
- [Http client](#http-client)
- [Database](#database)
- [Redis](#redis)
- [Queue jobs](#redis)
- [Logs context](#logs-context)
- [Manual traces](#manual-traces)

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
