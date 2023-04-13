# Changelog

All notable changes to `laravel-opentelemetry` will be documented in this file.

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
