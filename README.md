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
use Keepsuit\LaravelOpenTelemetry\Watchers;

return [
    /**
     * Service name
     */
    'service_name' => \Illuminate\Support\Str::slug(env('APP_NAME', 'laravel-app')),

    /**
     * Enable tracing
     * Valid values: 'true', 'false', 'parent'
     */
    'enabled' => env('OT_ENABLED', true),

    /**
     * Exporter to use
     * Supported: 'zipkin', 'http', 'grpc', 'console', 'null'
     */
    'exporter' => env('OT_EXPORTER', 'http'),

    /**
     * Propagator to use
     * Supported: 'b3', 'b3multi', 'tracecontext',
     */
    'propagator' => env('OT_PROPAGATOR', 'tracecontext'),

    /**
     * Http paths not to trace
     */
    'excluded_paths' => [],

    /**
     * Grpc services not to trace
     */
    'excluded_services' => [],

    /**
     * List of watcher used for application tracing
     */
    'watchers' => [
        Watchers\QueryWatcher::class => env('OT_WATCHER_QUERY', true),

        Watchers\RedisWatcher::class => env('OT_WATCHER_REDIS', true),

        Watchers\QueueWatcher::class => env('OT_WATCHER_QUEUE', true),
    ],

    /**
     * Exporters config
     */
    'exporters' => [
        'zipkin' => [
            'endpoint' => env('OT_ZIPKIN_HTTP_ENDPOINT', 'http://localhost:9411'),
        ],

        'http' => [
            'endpoint' => env('OT_OTLP_HTTP_ENDPOINT', 'http://localhost:4318'),
        ],

        'grpc' => [
            'endpoint' => env('OT_OTLP_GRPC_ENDPOINT', 'http://localhost:4317'),
        ],
    ],

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

To trace http requests add the `\Keepsuit\LaravelOpenTelemetry\Http\Server\TraceRequestMiddleware::class` middleware.
You can add it to the global middleware stack in `app/Http/Kernel.php` or only to specific routes.
It is recommended to set it as the first middleware in the stack.

```php
protected $middleware = [
    \Keepsuit\LaravelOpenTelemetry\Http\Server\TraceRequestMiddleware::class,
    // ...
];
```

### Http client

To trace an outgoing http request call the `withTrace` method on the request builder.

```php
Http::withTrace()->get('https://example.com');
```

### Database

Database queries are automatically traced.
You can disable it by setting `OT_WATCHER_QUERY` to `false` or removing the `QueryWatcher::class` from the config file.

### Redis

Redis commands are automatically traced.
You can disable it by setting `OT_WATCHER_REDIS` to `false` or removing the `RedisWatcher::class` from the config file.

### Queue jobs

Queue jobs are automatically traced.
You can disable it by setting `OT_WATCHER_QUEUE` to `false` or removing the `QueueWatcher::class` from the config file.

### Logs context

When starting a trace with provided instrumentation, the trace id is automatically injected in the log context.
This allows to correlate logs with traces.

If you are starting the root trace manually, 
you should call `Tracer::setRootSpan($span)` to inject the trace id in the log context.

### Manual traces

The simplest way to create a custom trace is with `measure` method:

```php
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;

Tracer::measure('my custom trace', function () {
    // do something
});
```

Alternatively you can manage the span manually:

```php
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;

$span = Tracer::start('my custom trace');

// do something

$span->end();
```

With `measure` the span is automatically set to active (so it will be used as parent for new spans).
With `start` you have to manually set the span as active:

```php
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;

$span = Tracer::start('my custom trace');
$scope = $span->activate()

// do something

$span->end();
$scope->detach();
```

Other utility methods are available on the `Tracer` facade:

```php
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;

Tracer::isRecording(); // check if tracing is enabled
Tracer::activeSpan(); // get the active span
Tracer::activeScope(); // get the active scope
Tracer::traceId(); // get the active trace id
Tracer::propagationHeaders(); // get the propagation headers required to propagate the trace to other services
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
