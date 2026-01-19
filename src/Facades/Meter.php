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
 * @method static CounterInterface counter(string $name, ?string $unit = null, ?string $description = null, array $advisory = [])
 * @method static HistogramInterface histogram(string $name, ?string $unit = null, ?string $description = null, array $advisory = [])
 * @method static GaugeInterface gauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = [])
 * @method static UpDownCounterInterface upDownCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = [])
 * @method static ObservableCounterInterface observableCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = [])
 * @method static ObservableGaugeInterface observableGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = [])
 * @method static ObservableUpDownCounterInterface observableUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = [])
 * @method static ObservableCallbackInterface batchObserve(AsynchronousInstrument[] $instruments, callable $callback)
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
