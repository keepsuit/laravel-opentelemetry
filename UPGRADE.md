# Upgrade Guide

## Upgrading to v2.0

This guide covers the breaking changes and new features introduced in v2.0. Please review all sections carefully before upgrading.

### Overview

v2.0 is a major release that introduces significant architectural improvements and new features:

- **Worker Mode Detection**: Automatic detection and optimization for long-running processes (Octane, Horizon, Queue workers)
- **Tail Sampling**: Advanced trace sampling with rules for filtering based on errors and trace duration
- **Scout Instrumentation**: First-class support for Laravel Scout search operations
- **Unified OpenTelemetry Facade**: New central API for accessing Tracer, Meter, Logger, and user context

---

### Breaking Changes

#### Configuration Structure Changes

> [!NOTE]
> If you have published the configuration file, compare it with the new version and update your existing config accordingly.

**Event Instrumentation:**

The `ignored` param has been renamed to `excluded`

**Console Instrumentation:**

The console instrumentation behavior has been reversed: instead of excluding commands (with `excluded`), you now specify which commands to include (with `commands`).
By default, commands are not traced.

**Logs Configuration:**

The `trace_id_field` default value has changed from `traceid` to `trace_id` to follow opentelemetry conventions.

#### HTTP Instrumentation - New Configuration Options

Http instrumentations (both server and client) have a new configuration option `sensitive_query_parameters` to specify which query parameters should be treated as sensitive and redacted from traces.

#### Namespace Changes

Several classes have been reorganized for better code structure. If you're importing these classes directly, update your imports:

| Old Namespace (v1.x)                                                      | New Namespace (v2.0)                                                                       |
|---------------------------------------------------------------------------|--------------------------------------------------------------------------------------------|
| `Keepsuit\LaravelOpenTelemetry\Support\InstrumentationUtilities`          | `Keepsuit\LaravelOpenTelemetry\Instrumentation\Support\InstrumentationUtilities`           |
| `Keepsuit\LaravelOpenTelemetry\Support\View\TracedViewEngine`             | `Keepsuit\LaravelOpenTelemetry\Instrumentation\Support\View\TracedViewEngine`              |
| `Keepsuit\LaravelOpenTelemetry\Support\HttpClient\GuzzleTraceMiddleware`  | `Keepsuit\LaravelOpenTelemetry\Instrumentation\Support\Http\Client\GuzzleTraceMiddleware`  |
| `Keepsuit\LaravelOpenTelemetry\Support\HttpServer\TraceRequestMiddleware` | `Keepsuit\LaravelOpenTelemetry\Instrumentation\Support\Http\Server\TraceRequestMiddleware` |

#### Meter API Changes

All `Meter` class methods have been renamed and refactored for a cleaner API and better instrument management.

**Method Renames:**

All `create*` method prefixes have been removed for brevity:

| v1.x Method                       | v2.0 Method                 |
|-----------------------------------|-----------------------------|
| `createCounter()`                 | `counter()`                 |
| `createGauge()`                   | `gauge()`                   |
| `createHistogram()`               | `histogram()`               |
| `createUpDownCounter()`           | `upDownCounter()`           |
| `createObservableCounter()`       | `observableCounter()`       |
| `createObservableGauge()`         | `observableGauge()`         |
| `createObservableUpDownCounter()` | `observableUpDownCounter()` |

**Observable Instrument Changes:**

Observable instrument methods (`observableCounter()`, `observableGauge()`, `observableUpDownCounter()`) no longer accept `callable ...$callbacks` parameters.
You must now register observation callbacks using the `observe()` method on the returned instrument.

**Before (v1.x):**

```php
Meter::createObservableCounter('cache.hits', 'Cache hits', function(ObserverInterface $observer): void {
    $observer->observe(123);
});
```

**After (v2.0):**

```php
Meter::observableCounter('cache.hits', 'Cache hits')
    ->observe(function (ObserverInterface $observer) {
        $observer->observe(123);
    });
```

**`batchObserve()` Method Changes:**

The method signature has changed: instruments array is now the first parameter, callback is the second parameter.

**Before (v1.x):**

```php
Meter::batchObserve(
    function(
        ObserverInterface $usageObserver,
        ObserverInterface $pressureObserver,
    ): void {
        [$usage, $pressure] = expensive_system_call();
        $usageObserver->observe($usage);
        $pressureObserver->observe($pressure);
    },
    Meter::createObservableCounter('usage', description: 'count of items used'),
    Meter::createObservableGauge('pressure', description: 'force per unit area'),
);
```

**After (v2.0):**

```php
Meter::batchObserve(
    [
        Meter::observableCounter('usage', description: 'count of items used'),
        Meter::observableGauge('pressure', description: 'force per unit area'),
    ],
    function(
        ObserverInterface $usageObserver,
        ObserverInterface $pressureObserver,
    ): void {
        [$usage, $pressure] = expensive_system_call();
        $usageObserver->observe($usage);
        $pressureObserver->observe($pressure);
    }
);
```

**Instrument Caching:**

In v2.0, instruments are now cached by name.
Calling a meter method multiple times with the same name returns the cached instrument instead of creating duplicates.
This prevents accidental duplicate instruments and allows to access the same instrument from multiple places.

```php
// Both calls return the same instrument instance
$counter1 = Meter::counter('requests.total');
$counter2 = Meter::counter('requests.total');

assert($counter1 === $counter2); // true
```

If you attempt to create an instrument with the same name but different type, an exception will be thrown:

```php
$counter = Meter::counter('requests.total');
$gauge = Meter::gauge('requests.total'); // Throws RuntimeException
```

#### Null Exporter Behavior

In v1.0 when exporter was set to `null`, the 'in-memory' OTLP exporter was used.
In v2.0, setting exporter to `null` uses a no-op exporter that discards all data. To use the in-memory exporter, set exporter to `memory`.
When a signal (trace, metric, log) is disabled, the no-op exporter is used regardless of the configured exporter.

### Span names and attributes changes

Some span names and attributes have been renamed or changed to better align with OpenTelemetry conventions:
- Http spans (both client and server) now uses `{method} {route}` name convention
- Database spans (sql and redis) now uses `{operation name}` name convention
- Queue spans now uses `{operation} {queue name}` name convention

---

### New Features

#### Worker Mode Detection

v2.0 introduces automatic detection and optimization for long-running processes.
This is particularly useful for Laravel Octane, Horizon queue workers, and default queue workers.

When worker mode is detected, traces and metrics are collected and exported periodically:

- trace spans are exported when periodically when the buffer is full
- metrics are collected and exported at configurable intervals

When `flush_after_each_iteration` is true, all telemetry data is flushed after each request/job.

**Configuration:**

```php
'worker_mode' => [
    'flush_after_each_iteration' => false,  // Flush after each request/job
    'metrics_collect_interval' => 60,       // Collect metrics every 60 seconds
    'detectors' => [
        WorkerMode\Detectors\OctaneWorkerModeDetector::class,
        WorkerMode\Detectors\QueueWorkerModeDetector::class,
    ],
],
```

**Environment Variables:**

- `OTEL_WORKER_MODE_FLUSH_AFTER_EACH_ITERATION`: Set to `true` to flush after each iteration
- `OTEL_WORKER_MODE_COLLECT_INTERVAL`: Metrics collection interval in seconds

### Tail Sampling

Tail sampling allows you to make sampling decisions based on the complete trace data, rather than just the initial span.
This enables intelligent filtering of traces based on specific rules.

**Built-in Rules:**

- `ErrorsRule`: Keeps traces that contain error/exception spans
- `SlowTraceRule`: Keeps traces that exceed a configurable duration threshold

**Configuration:**

```php
'tail_sampling' => [
    'enabled' => false,
     // Maximum time to wait for the end of the trace before making a sampling decision (in milliseconds)
    'decision_wait' => 5000,  
    'rules' => [
        TailSampling\Rules\ErrorsRule::class => true,
        
        TailSampling\Rules\SlowTraceRule::class => [
            'enabled' => true,
            'threshold_ms' => 2000,
        ],
    ],
],
```

**When to use:**

- You want to reduce telemetry volume but keep important traces
- You want to automatically filter out fast, successful requests
- You're sampling at a low rate but want to ensure error traces are always captured

> [!NOTE]
> Tail sampling implemented at the application-level should only be used for single-service scenarios.
> For multi-service tail sampling, use the OpenTelemetry Collector instead because it has visibility into the complete trace across all services.

### Scout Instrumentation

v2.0 adds first-class support for tracing Laravel Scout search operations.
It traces search, update and delete operations performed via Scout.

**Requirements:**

- Laravel Scout 10.23+
- OpenTelemetry PHP extension installed

**Configuration:**

```php
Instrumentation\ScoutInstrumentation::class => env('OTEL_INSTRUMENTATION_SCOUT', true),
```

### Unified OpenTelemetry Facade

v2.0 introduces a new unified facade for accessing all OpenTelemetry APIs in a consistent way.
The old facades (`Tracer`, `Meter`, `Logger`) will continue to work like before.

**Available methods:**

```php
use Keepsuit\LaravelOpenTelemetry\Facades\OpenTelemetry;

// Access the tracer
OpenTelemetry::tracer();

// Access the meter
OpenTelemetry::meter();

// Access the logger
OpenTelemetry::logger();
```

### User Context

With v2.0 traces and logs can include authenticated user context information.
This features is enabled by default and records a `user.id` attribute to spans (root) and logs.

You can customize the recorded user information by providing a resolver callback:

```php
// In a service provider or bootstrap file
OpenTelemetry::user(function(Authenticatable $user) {
    return [
        'user.id' => (string) $user->id,
        'user.email' => $user->email,
        'user.name' => $user->name,
    ];
});
```

**Environment Variable:**

```bash
OTEL_USER_CONTEXT=true  # Enable/disable user context collection
```

---

## Performance Considerations

### Worker Mode

When running in worker mode (Octane, Horizon, etc.), v2.0 optimizes telemetry behavior:

- **Before v2.0**: Every recording was flushed after each request/job, like v2 with `flush_after_each_iteration` enabled
- **After v2.0**: Automatic memory management with proper flushing between iterations

**Recommendation:** Use worker mode detection by keeping the default configuration. Adjust `metrics_collect_interval` based on your needs.

### Tail Sampling

Tail sampling requires buffering complete traces before making decisions:

- **Memory impact**: Proportional to the number of active traces being buffered
- **Latency**: Adds `decision_wait` milliseconds before traces are exported

**Recommendation:** Enable only if you need advanced filtering. Start with a low `decision_wait` value and adjust based on your trace patterns.
