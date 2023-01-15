<?php

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
