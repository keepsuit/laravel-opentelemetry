<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Tests\TestCase;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function () {
        resetStorage();
    })
    ->afterAll(function () {
        resetStorage();
    })
    ->in(__DIR__);

function resetStorage(): void
{
    $tracerProvider = Globals::tracerProvider();
    assert($tracerProvider instanceof TracerProviderInterface);
    $tracerProvider->forceFlush();
    $tracerExporter = app(SpanExporterInterface::class);
    assert($tracerExporter instanceof InMemoryExporter);
    $tracerExporter->getStorage()->exchangeArray([]);

    $meterProvider = Globals::meterProvider();
    assert($meterProvider instanceof MeterProvider);
    $meterProvider->forceFlush();
    $meterExporter = app(MetricExporterInterface::class);
    assert($meterExporter instanceof OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter);
    $meterExporter->collect(reset: true);

    $loggerProvider = Globals::loggerProvider();
    assert($loggerProvider instanceof LoggerProvider);
    $loggerProvider->forceFlush();
    $loggerExporter = app(LogRecordExporterInterface::class);
    assert($loggerExporter instanceof OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter);
    $loggerExporter->getStorage()->exchangeArray([]);
}

function skipWithoutOtelExtension(): void
{
    if (! extension_loaded('opentelemetry')) {
        test()->markTestSkipped('OpenTelemetry extension is not loaded');
    }
}

/**
 * @return Collection<array-key,ImmutableSpan>
 */
function getRecordedSpans(): Collection
{
    $tracerProvider = Globals::tracerProvider();
    assert($tracerProvider instanceof TracerProviderInterface);
    $tracerProvider->forceFlush();

    $exporter = app(SpanExporterInterface::class);
    assert($exporter instanceof InMemoryExporter);

    return collect($exporter->getSpans());
}

function withRootSpan(Closure $callback): mixed
{
    return Tracer::newSpan('root')->measure($callback);
}

/**
 * @return Collection<array-key,Metric>
 */
function getRecordedMetrics(): Collection
{
    $meterProvider = Globals::meterProvider();
    assert($meterProvider instanceof MeterProvider);
    $meterProvider->forceFlush();

    $meterExporter = app(MetricExporterInterface::class);
    assert($meterExporter instanceof OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter);

    return collect($meterExporter->collect());
}

/**
 * @return Collection<array-key,LogRecord>
 */
function getRecordedLogs(): Collection
{
    $loggerProvider = Globals::loggerProvider();
    assert($loggerProvider instanceof LoggerProvider);
    $loggerProvider->forceFlush();

    $exporter = app(LogRecordExporterInterface::class);
    assert($exporter instanceof OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter);

    return collect($exporter->getStorage()->getArrayCopy());
}

function registerInstrumentation(string $instrumentation, array $options = []): void
{
    if (! isset($options['enabled'])) {
        $options['enabled'] = true;
    }

    app()->make($instrumentation)->register($options);
}
