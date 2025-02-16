<?php

namespace Keepsuit\LaravelOpenTelemetry\Facades;

use Illuminate\Support\Facades\Facade;
use OpenTelemetry\API\Metrics\AsynchronousInstrument;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\GaugeInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObservableCounterInterface;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;

/**
 * @method static ObservableCallbackInterface batchObserve(callable $callback, AsynchronousInstrument $instrument, AsynchronousInstrument ...$instruments)
 * @method static CounterInterface createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = [])
 * @method static ObservableCounterInterface createObservableCounter(string $name, ?string $unit = null, ?string $description = null, array|callable $advisory = [], callable ...$callbacks)
 * @method static HistogramInterface createHistogram(string $name, ?string $unit = null, ?string $description = null, array $advisory = [])
 * @method static GaugeInterface createGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = [])
 * @method static ObservableGaugeInterface createObservableGauge(string $name, ?string $unit = null, ?string $description = null, array|callable $advisory = [], callable ...$callbacks)
 * @method static UpDownCounterInterface createUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = [])
 * @method static ObservableUpDownCounterInterface createObservableUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array|callable $advisory = [], callable ...$callbacks)
 * @method static bool collect()
 *
 * @see \Keepsuit\LaravelOpenTelemetry\Meter
 */
class Meter extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Keepsuit\LaravelOpenTelemetry\Meter::class;
    }
}
