<?php

use OpenTelemetry\API\Common\Instrumentation\Globals;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

uses(\Keepsuit\LaravelOpenTelemetry\Tests\TestCase::class)->in(__DIR__);

/**
 * @return \OpenTelemetry\SDK\Trace\ImmutableSpan[]
 */
function getRecordedSpans(): array
{
    $exporter = app(\OpenTelemetry\SDK\Trace\SpanExporterInterface::class);
    assert($exporter instanceof \OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter);

    return $exporter->getSpans();
}

function flushSpans()
{
    $tracerProvider = Globals::tracerProvider();
    assert($tracerProvider instanceof TracerProviderInterface);

    $tracerProvider->forceFlush();

    return test();
}
