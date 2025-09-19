<?php

use Keepsuit\LaravelOpenTelemetry\Facades\Meter;
use OpenTelemetry\API\Metrics\ObserverInterface;

it('can build open telemetry meter', function () {
    /** @var \OpenTelemetry\API\Metrics\MeterInterface $meter */
    $meter = app(\OpenTelemetry\API\Metrics\MeterInterface::class);

    expect($meter)
        ->toBeInstanceOf(\OpenTelemetry\API\Metrics\MeterInterface::class);
});

test('counter', function () {
    $counter = Meter::createCounter('test_counter');

    expect($counter)->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Counter::class);

    $counter->add(1);
    $counter->add(2);

    $metrics = getRecordedMetrics();
    expect($metrics)->count()->toBe(1);

    expect($metrics->first())
        ->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class)
        ->name->toBe('test_counter')
        ->data->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Sum::class)
        ->data->dataPoints->{0}->value->toBe(3);
});

test('observable counter', function () {
    $counter = Meter::createObservableCounter('test_observable_counter');

    expect($counter)->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\ObservableCounter::class);

    $counter->observe(function (ObserverInterface $observer) {
        $observer->observe(5);
    });

    $metrics = getRecordedMetrics();
    expect($metrics)->count()->toBe(1);

    expect($metrics->first())
        ->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class)
        ->name->toBe('test_observable_counter')
        ->data->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Sum::class)
        ->data->dataPoints->{0}->value->toBe(5);
});

test('batch observer', function () {
    $counter1 = Meter::createObservableCounter('test_observable_1');
    $counter2 = Meter::createObservableCounter('test_observable_2');

    Meter::batchObserve(function (ObserverInterface $observer1, ObserverInterface $observer2) {
        $observer1->observe(10);
        $observer2->observe(30);
    }, $counter1, $counter2);

    $metrics = getRecordedMetrics();
    expect($metrics)->count()->toBe(2);

    expect($metrics->first())
        ->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class)
        ->name->toBe('test_observable_1')
        ->data->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Sum::class)
        ->data->dataPoints->{0}->value->toBe(10);

    expect($metrics->last())
        ->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class)
        ->name->toBe('test_observable_2')
        ->data->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Sum::class)
        ->data->dataPoints->{0}->value->toBe(30);
});

test('histogram', function () {
    $histogram = Meter::createHistogram('test_histogram');

    expect($histogram)->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Histogram::class);

    $histogram->record(10);
    $histogram->record(30);

    $metrics = getRecordedMetrics();
    expect($metrics)->count()->toBe(1);

    expect($metrics->first())
        ->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class)
        ->name->toBe('test_histogram')
        ->data->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Histogram::class);

    expect($metrics->first()->data->dataPoints[0])
        ->count->toBe(2)
        ->min->toBe(10)
        ->max->toBe(30)
        ->sum->toBe(40);
});

test('gauge', function () {
    $gauge = Meter::createGauge('test_gauge');

    expect($gauge)->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Gauge::class);

    $gauge->record(10);

    $metrics = getRecordedMetrics();
    expect($metrics)->count()->toBe(1);

    expect($metrics->first())
        ->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class)
        ->name->toBe('test_gauge')
        ->data->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Gauge::class)
        ->data->dataPoints->{0}->value->toBe(10);
});

test('observable gauge', function () {
    $gauge = Meter::createObservableGauge('test_observable_gauge');

    expect($gauge)->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\ObservableGauge::class);

    $gauge->observe(function (ObserverInterface $observer) {
        $observer->observe(20);
    });

    $metrics = getRecordedMetrics();
    expect($metrics)->count()->toBe(1);

    expect($metrics->first())
        ->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class)
        ->name->toBe('test_observable_gauge')
        ->data->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Gauge::class)
        ->data->dataPoints->{0}->value->toBe(20);
});

test('up/down counter', function () {
    $counter = Meter::createUpDownCounter('test_updown_counter');

    expect($counter)->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\UpDownCounter::class);

    $counter->add(5);
    $counter->add(-2);

    $metrics = getRecordedMetrics();
    expect($metrics)->count()->toBe(1);

    expect($metrics->first())
        ->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class)
        ->name->toBe('test_updown_counter')
        ->data->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Sum::class)
        ->data->dataPoints->{0}->value->toBe(3);
});

test('observable up/down counter', function () {
    $counter = Meter::createObservableUpDownCounter('test_observable_updown_counter');

    expect($counter)->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\ObservableUpDownCounter::class);

    $counter->observe(function (ObserverInterface $observer) {
        $observer->observe(10);
        $observer->observe(-4);
        $observer->observe(2);
    });

    $metrics = getRecordedMetrics();
    expect($metrics)->count()->toBe(1);

    expect($metrics->first())
        ->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class)
        ->name->toBe('test_observable_updown_counter')
        ->data->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Sum::class)
        ->data->dataPoints->{0}->value->toBe(8);
});

test('temporality configuration is applied to metrics', function () {
    // Test with Delta temporality (default)
    $counter = Meter::createCounter('test_temporality_counter');
    $counter->add(5);
    
    $metrics = getRecordedMetrics();
    expect($metrics)->count()->toBe(1);
    
    $sumData = $metrics->first()->data;
    expect($sumData)->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Sum::class);
    // Default should be Delta since test environment uses InMemoryExporter
    expect($sumData->temporality)->toBe('Delta');
});
