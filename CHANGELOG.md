# Changelog

All notable changes to `laravel-opentelemetry` will be documented in this file.

## 2.4.1 - 2026-11-19

### Fixes

**Delta metrics no longer make Prometheus reject whole exports.** With Delta temporality, the OpenTelemetry PHP SDK exports every instrument on each collection, also when nothing was recorded since the previous one. Such a metric has no data points. Prometheus' OTLP endpoint rejects the whole request when it finds one, so every metric in the same export was lost. Typical cases:

- an idle `laravel.reverb.messages` took `laravel.reverb.active_connections` down with it;
- an idle histogram in a queue worker took the worker's other metrics down with it.

The OTLP metrics exporter now leaves out metrics without data points, and makes no request when nothing is left. Cumulative exports are unaffected.

If you use Delta and Prometheus, look for `empty data points. <metric> is dropped` in the Prometheus logs, or for HTTP 500 errors in your collector's exporter, to see whether you were affected.

### What's Changed

* Leave metrics without data points out of OTLP exports by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/122

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/2.4.0...2.4.1

## 2.4.0 - 2026-11-19

### Upgrade notes

**Laravel 12.45 or later is required** (`illuminate/*` `^12.45 || ^13.0`). It is the first version with the `CommandFailed` Redis event. Composer already blocks earlier 12.x releases because of security advisories. Apps on older versions stay on 2.3.x.

**Published config files need new keys.** The config is merged one level deep, so a published `exporters`, `logs` or `worker_mode` array replaces the package one, and keys added in this release are missing. Without them the related environment variables are ignored and the defaults below apply:

| Key | Add | Without it |
|---|---|---|
| `exporters.otlp.connect_timeout` | `(int) env('OTEL_EXPORTER_OTLP_CONNECT_TIMEOUT', 1000)` | `OTEL_EXPORTER_OTLP_CONNECT_TIMEOUT` is ignored, 1000 ms |
| `exporters.otlp.failure_cooldown` | `(int) env('OTEL_EXPORTER_OTLP_FAILURE_COOLDOWN', 30)` | `OTEL_EXPORTER_OTLP_FAILURE_COOLDOWN` is ignored, the cooldown is on for 30s |
| `exporters.otlp.max_retries` | change the default from `3` to `1` | the published default stays 3 |
| `logs.level` | `env('LOG_LEVEL', 'debug')` | the `otlp` channel keeps exporting `debug` and above |
| `worker_mode.flush_interval` | `(int) env('OTEL_WORKER_MODE_FLUSH_INTERVAL', env('OTEL_WORKER_MODE_COLLECT_INTERVAL', 60))` | `metrics_collect_interval` and `OTEL_WORKER_MODE_COLLECT_INTERVAL` are still read |
| `instrumentation` | `Instrumentation\ReverbInstrumentation::class => env('OTEL_INSTRUMENTATION_REVERB', true)` | no Reverb metrics |
| `worker_mode.detectors` | `WorkerMode\Detectors\ReverbWorkerModeDetector::class` | `reverb:start` exports only at shutdown |

**Failed exports are much cheaper.** Exports are synchronous, and every PHP process also exports at shutdown, short Artisan commands included. Before, an unreachable collector could block each export for 10s × 4 attempts.

- Over HTTP, a new connect timeout (`OTEL_EXPORTER_OTLP_CONNECT_TIMEOUT`, default 1000 ms, needs Guzzle) makes an unreachable host fail fast.
- Retries default to 1 instead of 3.
- gRPC exports now honour `OTEL_EXPORTER_OTLP_TIMEOUT`.
- After an export fails (unreachable collector, timeout or 5xx), every export to that collector is dropped for 30 seconds (`OTEL_EXPORTER_OTLP_FAILURE_COOLDOWN`, `0` disables it). The pause is shared by every process of the application through a file in `storage/framework/cache`. When it expires, a single process tries again. Telemetry is lost for up to one cooldown, but under PHP-FPM or FrankenPHP requests no longer pay a failed export each. In a load test with the collector down, web throughput went from 13–32 req/s back to about 90–105 req/s.

See the new "Export Failures" section of the README. For a collector in the same host or cluster, consider `OTEL_EXPORTER_OTLP_CONNECT_TIMEOUT=250` and `OTEL_EXPORTER_OTLP_TIMEOUT=2000`.

**Worker mode flushes every signal.** The periodic interval used to collect only metrics. It now also exports pending spans and log records. The interval is renamed to `flush_interval` / `OTEL_WORKER_MODE_FLUSH_INTERVAL`, and the old names still work.

**Logs**

- Data added with Laravel's `Context` facade is exported as log record attributes. Hidden context is not exported.
- The injected `otlp` channel follows `LOG_LEVEL` (`opentelemetry.logs.level`) instead of always `debug`.

**Emitted telemetry changes.** Update dashboards, alerts and queries that rely on the old names or values:

| Signal | Before | After |
|---|---|---|
| `http.server.request.duration` | `server.address`, `server.port` labels | removed |
| HTTP server spans and metric | `error.type` on 3xx/4xx | only on 5xx |
| HTTP server and client | unknown methods kept as sent | `_OTHER` (span name `HTTP`), original in `http.request.method_original` on spans |
| HTTP server spans | handled `report($e)` set status Error | status unchanged |
| HTTP server spans, 404 without route | `http.route = "/"` | no `http.route` |
| HTTP client spans | 4xx not an error | 4xx sets status Error |
| HTTP server spans | `server.address` could include the port | host only |
| HTTP client spans and metric | no `server.port` when the URL has no port | `server.port` 80 / 443 |
| All spans | status `Ok` set explicitly | status left `Unset` |
| DB spans | name `SELECT` | name includes the database, e.g. `SELECT app` |
| `db.client.operation.duration` | `db.query.text` label | removed |
| DB spans | `server.port` as string | integer, omitted for the DBMS default port |
| Redis spans | keys and values in `db.query.text` | replaced by `?` |
| Redis | failed commands not recorded | span with `error.type`, no metric data point |
| Redis spans and metric | no `server.port` | `server.port` when not 6379 |
| Scout spans and metric | no `error.type` | `error.type` on failure |
| Scout spans without target | `"<op> "` | `"<op>"` |
| `user.id` | integer | string |

`QUERY` is now a known HTTP method.

**New: Laravel Reverb.** `reverb:start` exports periodically from its event loop and records `laravel.reverb.messages` and `laravel.reverb.active_connections` (Reverb 1.14+). Exports block the event loop, so keep the collector close and the timeouts low.

**Metrics temporality.** `OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE` is now case-insensitive. Unknown values fall back to cumulative.

### What's Changed

* Fix CI with latest OpenTelemetry SDK and Zipkin deprecation by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/110
* Accept metrics temporality preference in any case by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/111
* Document metrics limitations in per-request runtimes by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/106
* Drop db.query.text from DB metrics and sanitize Redis query text by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/109
* Fix HTTP server error.type, metric attributes and unknown methods by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/107
* Align span names and status with semantic conventions by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/108
* fix(logs): forward Monolog extra (Laravel Context) to OTLP log records by @andrewbroberg in https://github.com/keepsuit/laravel-opentelemetry/pull/117
* fix(logs): respect LOG_LEVEL on the injected otlp channel by @andrewbroberg in https://github.com/keepsuit/laravel-opentelemetry/pull/118
* Flush all signals on the worker mode interval by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/112
* Speed up the test suite by avoiding exports of span graphs in negated assertions by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/119
* Normalize unknown HTTP methods on the client and add QUERY by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/113
* Fix semantic convention correctness issues by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/114
* Record failed Redis commands, add error.type and server.port, require Laravel 12.45 by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/116
* Add Laravel Reverb support by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/115
* Make failed OTLP exports cheap: connect timeout, gRPC timeout and one retry by default by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/120
* Drop OTLP exports for a cooldown after a failed export by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/121

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/2.3.0...2.4.0

## v2.3.0 - 2026-11-04

### Upgrade notes

**Laravel 11 is no longer supported.** Every `laravel/framework` 11.x release is covered by unpatched security advisories, so Composer blocks it by default. Laravel 11 apps stay on 2.2.x.

**Emitted telemetry changes** (semantic conventions 1.45). Update dashboards, alerts and queries that rely on the old keys or values:

| Spans / metrics | Before | After |
|---|---|---|
| Queue spans | `messaging.system` | `messaging.system.name` |
| Queue spans, SQS driver | `messaging.system = sqs` | `messaging.system.name = aws.sqs` |
| DB spans and `db.client.operation.duration`, PostgreSQL | `db.system.name = pgsql` | `db.system.name = postgresql` |
| DB spans and `db.client.operation.duration`, SQL Server | `db.system.name = sqlsrv` | `db.system.name = microsoft.sql_server` |

Queue spans now also carry `messaging.operation.name` (`send` / `process`), and failed job spans carry `error.type` (the exception class). The instrumentation schema URL is now 1.45.0, and `open-telemetry/sem-conv` `^1.45` is required.

**Repeated boots no longer leak SDK providers.** In processes that boot the application more than once, such as a test suite, the previous SDK scope is now detached. Memory stays flat and suites no longer slow down as they grow.

### What's Changed

* Drop Laravel 11 support by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/99
* Align with semantic conventions 1.45 by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/98
* Detach the previous SDK scope when the provider boots again by @stplva in https://github.com/keepsuit/laravel-opentelemetry/pull/97

### New Contributors

* @stplva made their first contribution in https://github.com/keepsuit/laravel-opentelemetry/pull/97

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/2.2.8...2.3.0

## v2.2.8 - 2026-10-26

### What's Changed

* fix(http-server): prevent successful 2xx responses from being flagged as errors by @evoloshchuk in https://github.com/keepsuit/laravel-opentelemetry/pull/94
* Allow Guzzle 8, drop guzzle7-adapter, fix PHPStan errors by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/95

### New Contributors

* @evoloshchuk made their first contribution in https://github.com/keepsuit/laravel-opentelemetry/pull/94

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/2.2.7...2.2.8

## v2.2.7 - 2026-10-09

### What's Changed

* fix: HTTP client traces to record failed requests by @andrewbroberg in https://github.com/keepsuit/laravel-opentelemetry/pull/93

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/2.2.6...2.2.7

## v2.2.6 - 2026-10-02

### What's Changed

* Fix tail sampling for traces that continue a remote parent by @meiraxx in https://github.com/keepsuit/laravel-opentelemetry/pull/92

### New Contributors

* @meiraxx made their first contribution in https://github.com/keepsuit/laravel-opentelemetry/pull/92

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/2.2.5...2.2.6

## v2.2.5 - 2026-09-11

### What's changed

- Fixed #91

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/2.2.4...2.2.5

## v2.2.4 - 2026-07-07

### What's Changed

* Bump actions/cache from 5 to 6 by @dependabot[bot] in https://github.com/keepsuit/laravel-opentelemetry/pull/88
* Fix queue span cleanup across job retries by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/90

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/2.2.3...2.2.4

## 2.2.3 - 2026-06-23

### What's Changed

* Fix leaked producer span on sync queue driver by @moysevich in https://github.com/keepsuit/laravel-opentelemetry/pull/87

### New Contributors

* @moysevich made their first contribution in https://github.com/keepsuit/laravel-opentelemetry/pull/87

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/2.2.2...2.2.3

## v2.2.2 - 2026-06-22

### What's Changed

* Bump actions/checkout from 6 to 7 by @dependabot[bot] in https://github.com/keepsuit/laravel-opentelemetry/pull/85
* Preserve root path in HTTP excluded paths by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/86

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/2.2.1...2.2.2

## v2.2.1 - 2026-05-09

### What's Changed

* Normalize boolean env values in opentelemetry config. by @Scott-McMullan-ABB in https://github.com/keepsuit/laravel-opentelemetry/pull/82

### New Contributors

* @Scott-McMullan-ABB made their first contribution in https://github.com/keepsuit/laravel-opentelemetry/pull/82

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/2.2.0...2.2.1

## v2.2.0 - 2026-03-23

### What's Changed

* Allow to disabled OTEL SDK from config by @edem-yakubov in https://github.com/keepsuit/laravel-opentelemetry/pull/80

### New Contributors

* @edem-yakubov made their first contribution in https://github.com/keepsuit/laravel-opentelemetry/pull/80

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/2.1.0...2.2.0

## v2.1.0 - 2026-03-18

### What's Changed

* Examples for console instrumentation by @VampireAotD in https://github.com/keepsuit/laravel-opentelemetry/pull/78
* Bump ramsey/composer-install from 3 to 4 by @dependabot[bot] in https://github.com/keepsuit/laravel-opentelemetry/pull/79
* support laravel 13 by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/77

### New Contributors

* @VampireAotD made their first contribution in https://github.com/keepsuit/laravel-opentelemetry/pull/78

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/2.0.0...2.1.0

## v2.0.0 - 2026-02-04

### What's Changed

This is a major release that introduces significant architectural improvements and new features to the Laravel OpenTelemetry package. This release implements worker mode detection, tail sampling, Scout instrumentation, unified OpenTelemetry facade, and user context tracking, along with some breaking API changes.

**Changes:**

- Introduces worker mode detection and optimization for long-running processes (Octane, Horizon, Queue workers)
- Adds tail sampling with configurable rules for intelligent trace filtering
- Implements Laravel Scout instrumentation for search operations
- Refactors Meter API (renamed methods, instrument caching, observable instrument changes)
- Updates span naming conventions to follow OpenTelemetry specifications
- Adds user context tracking for traces and logs

See [UPGRADE.md](UPGRADE.md) for breaking changes and upgrade guide

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/1.15.0...2.0.0

## v 1.15.0 - 2025-12-24

### What's Changed

* Bump actions/cache from 4 to 5 by @dependabot[bot] in https://github.com/keepsuit/laravel-opentelemetry/pull/64
* record correct request attribute when using trusted proxy by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/66
* set resource attributes from config by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/68

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/1.14.0...1.15.0

## v1.14.0 - 2025-11-30

### What's Changed

* Bump actions/checkout from 5 to 6 by @dependabot[bot] in https://github.com/keepsuit/laravel-opentelemetry/pull/58
* Add support for per signal protocol selection by @patrickratzow in https://github.com/keepsuit/laravel-opentelemetry/pull/61
* Add support for custom logs & traces processors by @patrickratzow in https://github.com/keepsuit/laravel-opentelemetry/pull/60

### New Contributors

* @patrickratzow made their first contribution

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/1.13.1...1.14.0

## v1.13.1 - 2025-11-09

### What's changed

- Excluded some long running commands from console instrumentation

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/1.13.0...1.13.1

## v1.13.0 - 2025-10-24

### What's Changed

* Gracefully disable otel by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/55
* Add support for excluding HTTP methods from tracing by @Copilot in https://github.com/keepsuit/laravel-opentelemetry/pull/56
* Bump stefanzweifel/git-auto-commit-action from 6 to 7 by @dependabot[bot] in https://github.com/keepsuit/laravel-opentelemetry/pull/54

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/1.12.0...1.13.0

## v1.12.0 - 2025-09-21

### What's Changed

* Add comprehensive GitHub Copilot instructions for Laravel OpenTelemetry development by @Copilot in https://github.com/keepsuit/laravel-opentelemetry/pull/50
* Add metrics temporality exporter configuration by @cappuc  in https://github.com/keepsuit/laravel-opentelemetry/pull/48
* allow to set custom service instance id by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/51

`service_instance_id` and `exporters.otlp.metrics_telemetry` config keys has been added. Check the default configuration for reference.

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/1.11.0...1.12.0

## v1.11.0 - 2025-09-15

### What's Changed

* feat: add Meter/Metrics support by @aurimasniekis in https://github.com/keepsuit/laravel-opentelemetry/pull/40

A `metrics` options has been added to the config to configure Otel meter, check the default configuration for reference.

### New Contributors

* @aurimasniekis made their first contribution in https://github.com/keepsuit/laravel-opentelemetry/pull/40

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/1.10.0...1.11.0

## v1.10.0 - 2025-09-03

### What's Changed

* Updated opentelemetry sdk and sem-conv
* Bump aglipanci/laravel-pint-action from 2.5 to 2.6 by @dependabot[bot] in https://github.com/keepsuit/laravel-opentelemetry/pull/45
* Bump actions/checkout from 4 to 5 by @dependabot[bot] in https://github.com/keepsuit/laravel-opentelemetry/pull/46

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/1.9.0...1.10.0

## v1.9.0 - 2025-08-02

### What's Changed

* fix: enhance exception tracing in http server instrumentation by @plumthedev in https://github.com/keepsuit/laravel-opentelemetry/pull/44

### New Contributors

* @plumthedev made their first contribution in https://github.com/keepsuit/laravel-opentelemetry/pull/44

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/1.8.1...1.9.0

## v1.8.1 - 2025-07-14

### What's Changed

* fix: correct spelling for app bootstrap span by @andrewbroberg in https://github.com/keepsuit/laravel-opentelemetry/pull/41
* flush otel recordings between jobs processing by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/42

### New Contributors

* @andrewbroberg made their first contribution in https://github.com/keepsuit/laravel-opentelemetry/pull/41

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/1.8.0...1.8.1

## v1.8.0 - 2025-06-29

### What's Changed

* Console commands tracing by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/39

#### Upgrade guide

Add the `ConsoleInstrumentation` to the config `instrumentation` array:

```php
Instrumentation\ConsoleInstrumentation::class => [
    'enabled' => env('OTEL_INSTRUMENTATION_CONSOLE', true),
    'excluded' => [],
],
























```
**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/1.7.0...1.8.0

## v1.7.0 - 2025-06-20

### What's Changed

* Http client global by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/38

#### Upgrade guide

In order to disable http client global tracing and keep the old behaviour, add `'manual' => true` to the instrumentation config

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/1.6.0...1.7.0

## v1.6.0 - 2025-06-19

### What's Changed

* Bump stefanzweifel/git-auto-commit-action from 5 to 6 by @dependabot in https://github.com/keepsuit/laravel-opentelemetry/pull/35
* Livewire v3 instrumentation by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/36
* added app boostrap span by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/37

#### Upgrade guide

If you have published the config file, add `Instrumentation\LivewireInstrumentation::class => env('OTEL_INSTRUMENTATION_LIVEWIRE', true)` to instrumentation array of the config.

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/1.5.0...1.6.0

## v1.5.0 - 2025-06-16

### What's Changed

* view tracing by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/34

#### Upgrade guide

If you have published the config file, add `Instrumentation\ViewInstrumentation::class => env('OTEL_INSTRUMENTATION_VIEW', true)` to `instrumentation` array of the config.

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/1.4.0...1.5.0

## v1.4.0 - 2025-05-23

### What's Changed

* Skip recording of internal spans when trace is not started by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/33

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/1.3.0...1.4.0

## v1.3.0 - 2025-03-22

### What's Changed

* allow to add custom headers to otlp exporter by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/32

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/1.2.0...1.3.0

## v1.2.0 - 2025-02-23

### What's Changed

* replaced deprecated `db.system` with `db.system.name`
* Support laravel 12
* Bump aglipanci/laravel-pint-action from 2.4 to 2.5 by @dependabot in https://github.com/keepsuit/laravel-opentelemetry/pull/30

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/1.1.0...1.2.0

## v1.1.0 - 2025-01-16

### What's Changed

* Add support for timeout and maxRetries by @ilicmilan in https://github.com/keepsuit/laravel-opentelemetry/pull/28

### New Contributors

* @ilicmilan made their first contribution in https://github.com/keepsuit/laravel-opentelemetry/pull/28

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/1.0.0...1.1.0

## v1.0.0 - 2024-10-06

### Breaking changes

This release contains a lot of breaking changes

* Compare your published config and update it accordingly.
* ENV variables prefix is changed from `OT` to `OTEL` and some env variables are changed.
* Tracer has been completely refactored, manual traces must been updated with the new methods.

`Tracer::start`, `Tracer::measure` and `Tracer::measureAsync` has been removed and replaced with:

```php
Tracer::newSpan('name')->start(); // same as old start
Tracer::newSpan('name')->measure(callback); // same as old measure
Tracer::newSpan('name')->setSpanKind(SpanKind::KIND_PRODUCER)->measure(callback); // same as old measureAsync
































```
`Tracer::recordExceptionToSpan` has been removed and exception should be recorded directly to span: `$span->recordException($exception)`

`Tracer::setRootSpan($span)` has been removed, it was only used to share traceId with log context. This has been replaced with `Tracer::updateLogContext()`

##### Logging

This release introduce a custom log channel for laravel `otlp` that allows to collect laravel logs with OpenTelemetry.
This is the injected `otlp` channel:

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
### What's Changed

* Refactoring tracer api by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/12
* Track http headers by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/13
* Improved http client tests by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/15
* Drop laravel 9 by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/16
* Auto queue instrumentation by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/14
* Follow OTEL env variables specifications by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/17
* Add support for logs by @cappuc in https://github.com/keepsuit/laravel-opentelemetry/pull/20

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/0.4.0...1.0.0

## v0.4.1 - 2024-10-06

### What's changed

* Fix #27

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/0.4.0...0.4.1

## v0.4.0 - 2024-01-05

### What's Changed

* Replaced deprecated trace attributes
* Increased phpstan level to 7
* Required stable versions of opentelemetry
* Bump actions/checkout from 3 to 4 by @dependabot in https://github.com/keepsuit/laravel-opentelemetry/pull/6
* Bump stefanzweifel/git-auto-commit-action from 4 to 5 by @dependabot in https://github.com/keepsuit/laravel-opentelemetry/pull/7
* Bump aglipanci/laravel-pint-action from 2.3.0 to 2.3.1 by @dependabot in https://github.com/keepsuit/laravel-opentelemetry/pull/8

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/0.3.2...0.4.0

## v0.3.1 - 2023-07-10

### What's Changed

- Support for latest open-temetry sdk (which contains some namespace changes)

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/0.3.0...0.3.1

## v0.3.0 - 2023-04-13

### What's Changed

- Changed some span names and attributes values to better respect specs
- Added `CacheInstrumentation` which records cache events
- Added `EventInstrumentation` which records dispatched events

**Full Changelog**: https://github.com/keepsuit/laravel-opentelemetry/compare/0.2.0...0.3.0

## v0.2.0 - 2023-03-02

- Removed deprecated `open-telemetry/sdk-contrib` and use `open-telemetry/exporter-*` packages

## v0.1.0 - 2023-01-20

- First release
