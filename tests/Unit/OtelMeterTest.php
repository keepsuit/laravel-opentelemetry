<?php

use Keepsuit\LaravelOpenTelemetry\Facades\Meter;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\SDK\Metrics\Counter;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\Gauge;
use OpenTelemetry\SDK\Metrics\Histogram;
use OpenTelemetry\SDK\Metrics\ObservableCounter;
use OpenTelemetry\SDK\Metrics\ObservableGauge;
use OpenTelemetry\SDK\Metrics\ObservableUpDownCounter;
use OpenTelemetry\SDK\Metrics\UpDownCounter;

it('can build open telemetry meter', function () {
    /** @var MeterInterface $meter */
    $meter = app(MeterInterface::class);

    expect($meter)
        ->toBeInstanceOf(MeterInterface::class);
});

test('counter', function () {
    $counter = Meter::counter('test_counter');

    expect($counter)->toBeInstanceOf(Counter::class);

    $counter->add(1);
    $counter->add(2);

    $data = getRecordedMetrics()->firstWhere('name', 'test_counter');

    expect($data)
        ->toBeInstanceOf(Metric::class)
        ->name->toBe('test_counter')
        ->data->toBeInstanceOf(Sum::class)
        ->data->dataPoints->{0}->value->toBe(3);
});

test('observable counter', function () {
    $counter = Meter::observableCounter('test_observable_counter');

    expect($counter)->toBeInstanceOf(ObservableCounter::class);

    $counter->observe(function (ObserverInterface $observer) {
        $observer->observe(5);
    });

    $data = getRecordedMetrics()->firstWhere('name', 'test_observable_counter');

    expect($data)
        ->toBeInstanceOf(Metric::class)
        ->name->toBe('test_observable_counter')
        ->data->toBeInstanceOf(Sum::class)
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
        ->toBeInstanceOf(Metric::class)
        ->name->toBe('test_observable_1')
        ->data->toBeInstanceOf(Sum::class)
        ->data->dataPoints->{0}->value->toBe(10);

    expect($data2)
        ->toBeInstanceOf(Metric::class)
        ->name->toBe('test_observable_2')
        ->data->toBeInstanceOf(Sum::class)
        ->data->dataPoints->{0}->value->toBe(30);
});

test('histogram', function () {
    $histogram = Meter::histogram('test_histogram');

    expect($histogram)->toBeInstanceOf(Histogram::class);

    $histogram->record(10);
    $histogram->record(30);

    $data = getRecordedMetrics()->firstWhere('name', 'test_histogram');

    expect($data)
        ->toBeInstanceOf(Metric::class)
        ->name->toBe('test_histogram')
        ->data->toBeInstanceOf(OpenTelemetry\SDK\Metrics\Data\Histogram::class);

    expect($data->data->dataPoints[0])
        ->count->toBe(2)
        ->min->toBe(10)
        ->max->toBe(30)
        ->sum->toBe(40);
});

test('gauge', function () {
    $gauge = Meter::gauge('test_gauge');

    expect($gauge)->toBeInstanceOf(Gauge::class);

    $gauge->record(10);

    $data = getRecordedMetrics()->firstWhere('name', 'test_gauge');

    expect($data)
        ->toBeInstanceOf(Metric::class)
        ->name->toBe('test_gauge')
        ->data->toBeInstanceOf(OpenTelemetry\SDK\Metrics\Data\Gauge::class)
        ->data->dataPoints->{0}->value->toBe(10);
});

test('observable gauge', function () {
    $gauge = Meter::observableGauge('test_observable_gauge');

    expect($gauge)->toBeInstanceOf(ObservableGauge::class);

    $gauge->observe(function (ObserverInterface $observer) {
        $observer->observe(20);
    });

    $data = getRecordedMetrics()->firstWhere('name', 'test_observable_gauge');

    expect($data)
        ->toBeInstanceOf(Metric::class)
        ->name->toBe('test_observable_gauge')
        ->data->toBeInstanceOf(OpenTelemetry\SDK\Metrics\Data\Gauge::class)
        ->data->dataPoints->{0}->value->toBe(20);
});

test('up/down counter', function () {
    $counter = Meter::upDownCounter('test_updown_counter');

    expect($counter)->toBeInstanceOf(UpDownCounter::class);

    $counter->add(5);
    $counter->add(-2);

    $data = getRecordedMetrics()->firstWhere('name', 'test_updown_counter');

    expect($data)
        ->toBeInstanceOf(Metric::class)
        ->name->toBe('test_updown_counter')
        ->data->toBeInstanceOf(Sum::class)
        ->data->dataPoints->{0}->value->toBe(3);
});

test('observable up/down counter', function () {
    $counter = Meter::observableUpDownCounter('test_observable_updown_counter');

    expect($counter)->toBeInstanceOf(ObservableUpDownCounter::class);

    $counter->observe(function (ObserverInterface $observer) {
        $observer->observe(10);
        $observer->observe(-4);
        $observer->observe(2);
    });

    $data = getRecordedMetrics()->firstWhere('name', 'test_observable_updown_counter');

    expect($data)
        ->toBeInstanceOf(Metric::class)
        ->name->toBe('test_observable_updown_counter')
        ->data->toBeInstanceOf(Sum::class)
        ->data->dataPoints->{0}->value->toBe(8);
});
