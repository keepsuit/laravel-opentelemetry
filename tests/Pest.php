<?php

use OpenTelemetry\API\Globals;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

uses(\Keepsuit\LaravelOpenTelemetry\Tests\TestCase::class)->in(__DIR__);

function flushSpans()
{
    $tracerProvider = Globals::tracerProvider();
    assert($tracerProvider instanceof TracerProviderInterface);

    $tracerProvider->forceFlush();

    return test();
}

/**
 * @return \OpenTelemetry\SDK\Trace\ImmutableSpan[]
 */
function getRecordedSpans(): array
{
    flushSpans();

    $exporter = app(\OpenTelemetry\SDK\Trace\SpanExporterInterface::class);
    assert($exporter instanceof \OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter);

    return $exporter->getSpans();
}

function withRootSpan(Closure $callback): mixed
{
    $rootSpan = \Keepsuit\LaravelOpenTelemetry\Facades\Tracer::newSpan('root')->startSpan();
    $rootScope = $rootSpan->activate();

    $result = $callback();

    $rootScope->detach();
    $rootSpan->end();

    return $result;
}
