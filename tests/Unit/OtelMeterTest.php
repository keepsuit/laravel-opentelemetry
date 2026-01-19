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
    $counter = Meter::counter('test_counter');

    expect($counter)->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Counter::class);

    $counter->add(1);
    $counter->add(2);

    $data = getRecordedMetrics()->firstWhere('name', 'test_counter');

    expect($data)
        ->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class)
        ->name->toBe('test_counter')
        ->data->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Sum::class)
        ->data->dataPoints->{0}->value->toBe(3);
});

test('observable counter', function () {
    $counter = Meter::observableCounter('test_observable_counter');

    expect($counter)->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\ObservableCounter::class);

    $counter->observe(function (ObserverInterface $observer) {
        $observer->observe(5);
    });

    $data = getRecordedMetrics()->firstWhere('name', 'test_observable_counter');

    expect($data)
        ->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class)
        ->name->toBe('test_observable_counter')
        ->data->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Sum::class)
        ->data->dataPoints->{0}->value->toBe(5);
});

test('batch observer', function () {
    $counter1 = Meter::observableCounter('test_observable_1');
    $counter2 = Meter::observableCounter('test_observable_2');

    Meter::batchObserve([$counter1, $counter2], function (ObserverInterface $observer1, ObserverInterface $observer2) {
        $observer1->observe(10);
        $observer2->observe(30);
    });

    $data1 = getRecordedMetrics()->firstWhere('name', 'test_observable_1');
    $data2 = getRecordedMetrics()->firstWhere('name', 'test_observable_2');

    expect($data1)
        ->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class)
        ->name->toBe('test_observable_1')
        ->data->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Sum::class)
        ->data->dataPoints->{0}->value->toBe(10);

    expect($data2)
        ->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class)
        ->name->toBe('test_observable_2')
        ->data->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Sum::class)
        ->data->dataPoints->{0}->value->toBe(30);
});

test('histogram', function () {
    $histogram = Meter::histogram('test_histogram');

    expect($histogram)->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Histogram::class);

    $histogram->record(10);
    $histogram->record(30);

    $data = getRecordedMetrics()->firstWhere('name', 'test_histogram');

    expect($data)
        ->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class)
        ->name->toBe('test_histogram')
        ->data->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Histogram::class);

    expect($data->data->dataPoints[0])
        ->count->toBe(2)
        ->min->toBe(10)
        ->max->toBe(30)
        ->sum->toBe(40);
});

test('gauge', function () {
    $gauge = Meter::gauge('test_gauge');

    expect($gauge)->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Gauge::class);

    $gauge->record(10);

    $data = getRecordedMetrics()->firstWhere('name', 'test_gauge');

    expect($data)
        ->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class)
        ->name->toBe('test_gauge')
        ->data->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Gauge::class)
        ->data->dataPoints->{0}->value->toBe(10);
});

test('observable gauge', function () {
    $gauge = Meter::observableGauge('test_observable_gauge');

    expect($gauge)->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\ObservableGauge::class);

    $gauge->observe(function (ObserverInterface $observer) {
        $observer->observe(20);
    });

    $data = getRecordedMetrics()->firstWhere('name', 'test_observable_gauge');

    expect($data)
        ->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class)
        ->name->toBe('test_observable_gauge')
        ->data->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Gauge::class)
        ->data->dataPoints->{0}->value->toBe(20);
});

test('up/down counter', function () {
    $counter = Meter::upDownCounter('test_updown_counter');

    expect($counter)->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\UpDownCounter::class);

    $counter->add(5);
    $counter->add(-2);

    $data = getRecordedMetrics()->firstWhere('name', 'test_updown_counter');

    expect($data)
        ->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class)
        ->name->toBe('test_updown_counter')
        ->data->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Sum::class)
        ->data->dataPoints->{0}->value->toBe(3);
});

test('observable up/down counter', function () {
    $counter = Meter::observableUpDownCounter('test_observable_updown_counter');

    expect($counter)->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\ObservableUpDownCounter::class);

    $counter->observe(function (ObserverInterface $observer) {
        $observer->observe(10);
        $observer->observe(-4);
        $observer->observe(2);
    });

    $data = getRecordedMetrics()->firstWhere('name', 'test_observable_updown_counter');

    expect($data)
        ->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class)
        ->name->toBe('test_observable_updown_counter')
        ->data->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Sum::class)
        ->data->dataPoints->{0}->value->toBe(8);
});
