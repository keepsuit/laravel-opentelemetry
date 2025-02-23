# Changelog

All notable changes to `laravel-opentelemetry` will be documented in this file.

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
