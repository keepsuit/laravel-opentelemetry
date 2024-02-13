<?php

use Illuminate\Support\Collection;
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
 * @return Collection<array-key,\OpenTelemetry\SDK\Trace\ImmutableSpan>
 */
function getRecordedSpans(): Collection
{
    flushSpans();

    $exporter = app(\OpenTelemetry\SDK\Trace\SpanExporterInterface::class);
    assert($exporter instanceof \OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter);

    return collect($exporter->getSpans());
}

function withRootSpan(Closure $callback): mixed
{
    $rootSpan = \Keepsuit\LaravelOpenTelemetry\Facades\Tracer::newSpan('root')->start();
    $rootScope = $rootSpan->activate();

    $result = $callback();

    $rootScope->detach();
    $rootSpan->end();

    return $result;
}
