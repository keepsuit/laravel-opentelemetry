<?php

use Keepsuit\LaravelOpenTelemetry\Support\NonEmptyMetricExporter;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;

function deltaCounterExports(bool $decorated): array
{
    $inner = new InMemoryExporter(temporality: Temporality::DELTA);
    $reader = new ExportingReader($decorated ? new NonEmptyMetricExporter($inner) : $inner);
    $counter = MeterProvider::builder()->addReader($reader)->build()->getMeter('test')->createCounter('jobs');

    $counter->add(1);
    $reader->collect();
    $first = $inner->collect(reset: true);

    // Nothing recorded since the previous collection.
    $reader->collect();
    $second = $inner->collect(reset: true);

    return [$first, $second];
}

it('exports a delta counter without data points when nothing was recorded', function () {
    [, $second] = deltaCounterExports(decorated: false);

    expect($second)->toHaveCount(1)
        ->and($second[0]->data->dataPoints)->toBe([]);
});

it('leaves out metrics without data points', function () {
    [$first, $second] = deltaCounterExports(decorated: true);

    expect($first)->toHaveCount(1)
        ->and(collect($first)->map(fn (Metric $metric) => count($metric->data->dataPoints))->all())->toBe([1])
        ->and($second)->toBe([]);
});

it('keeps the temporality of the wrapped exporter', function () {
    $inner = new InMemoryExporter(temporality: Temporality::DELTA);
    $reader = new ExportingReader(new NonEmptyMetricExporter($inner));
    $counter = MeterProvider::builder()->addReader($reader)->build()->getMeter('test')->createCounter('jobs');

    $counter->add(2);
    $reader->collect();

    expect($inner->collect()[0]->data->temporality)->toBe(Temporality::DELTA);
});
