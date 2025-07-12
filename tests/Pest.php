<?php

use Illuminate\Support\Collection;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Globals;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

uses(\Keepsuit\LaravelOpenTelemetry\Tests\TestCase::class)
    ->beforeEach(function () {
        resetStorage();
    })
    ->in(__DIR__);

function resetStorage(): void
{
    $tracerProvider = Globals::tracerProvider();
    assert($tracerProvider instanceof TracerProviderInterface);
    $tracerProvider->forceFlush();
    $tracerExporter = app(\OpenTelemetry\SDK\Trace\SpanExporterInterface::class);
    assert($tracerExporter instanceof \OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter);
    $tracerExporter->getStorage()->exchangeArray([]);

    $meterProvider = Globals::meterProvider();
    assert($meterProvider instanceof \OpenTelemetry\SDK\Metrics\MeterProvider);
    $meterProvider->forceFlush();
    $meterExporter = app(\OpenTelemetry\SDK\Metrics\MetricExporterInterface::class);
    assert($meterExporter instanceof \OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter);
    $meterExporter->collect(reset: true);

    $loggerProvider = Globals::loggerProvider();
    assert($loggerProvider instanceof \OpenTelemetry\SDK\Logs\LoggerProvider);
    $loggerProvider->forceFlush();
    $loggerExporter = app(\OpenTelemetry\SDK\Logs\LogRecordExporterInterface::class);
    assert($loggerExporter instanceof \OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter);
    $loggerExporter->getStorage()->exchangeArray([]);
}

/**
 * @return Collection<array-key,\OpenTelemetry\SDK\Trace\ImmutableSpan>
 */
function getRecordedSpans(): Collection
{
    $tracerProvider = Globals::tracerProvider();
    assert($tracerProvider instanceof TracerProviderInterface);
    $tracerProvider->forceFlush();

    $exporter = app(\OpenTelemetry\SDK\Trace\SpanExporterInterface::class);
    assert($exporter instanceof \OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter);

    return collect($exporter->getSpans());
}

function withRootSpan(Closure $callback): mixed
{
    return Tracer::newSpan('root')->measure($callback);
}

/**
 * @return Collection<array-key,\OpenTelemetry\SDK\Metrics\Data\Metric>
 */
function getRecordedMetrics(): Collection
{
    $meterProvider = Globals::meterProvider();
    assert($meterProvider instanceof \OpenTelemetry\SDK\Metrics\MeterProvider);
    $meterProvider->forceFlush();

    $meterExporter = app(\OpenTelemetry\SDK\Metrics\MetricExporterInterface::class);
    assert($meterExporter instanceof \OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter);

    return collect($meterExporter->collect());
}

/**
 * @return Collection<array-key,\OpenTelemetry\API\Logs\LogRecord>
 */
function getRecordedLogs(): Collection
{
    $loggerProvider = Globals::loggerProvider();
    assert($loggerProvider instanceof \OpenTelemetry\SDK\Logs\LoggerProvider);
    $loggerProvider->forceFlush();

    $exporter = app(\OpenTelemetry\SDK\Logs\LogRecordExporterInterface::class);
    assert($exporter instanceof \OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter);

    return collect($exporter->getStorage()->getArrayCopy());
}

function registerInstrumentation(string $instrumentation, array $options = [])
{
    if (! isset($options['enabled'])) {
        $options['enabled'] = true;
    }

    app()->make($instrumentation)->register($options);
}
