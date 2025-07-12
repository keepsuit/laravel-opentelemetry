<?php

namespace Keepsuit\LaravelOpenTelemetry;

use OpenTelemetry\API\Metrics\AsynchronousInstrument;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\GaugeInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObservableCounterInterface;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use OpenTelemetry\SDK\Metrics\MetricReaderInterface;

class Meter
{
    public function __construct(
        protected MeterInterface $meter,
        protected MetricReaderInterface $reader
    ) {}

    /**
     * Creates a `Counter`.
     *
     * @param  string  $name  name of the instrument
     * @param  ?string  $unit  unit of measure
     * @param  ?string  $description  description of the instrument
     * @param  array  $advisory  an optional set of recommendations
     * @return CounterInterface created instrument
     *
     * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/metrics/api.md#counter-creation
     */
    public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): CounterInterface
    {
        return $this->meter->createCounter($name, $unit, $description, $advisory);
    }

    /**
     * Creates an `ObservableCounter`.
     *
     * @param  string  $name  name of the instrument
     * @param  ?string  $unit  unit of measure
     * @param  ?string  $description  description of the instrument
     * @param  array  $advisory  an optional set of recommendations
     * @param  callable  ...$callbacks  responsible for reporting measurements
     * @return ObservableCounterInterface created instrument
     *
     * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/metrics/api.md#asynchronous-counter-creation
     */
    public function createObservableCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = [], callable ...$callbacks): ObservableCounterInterface
    {
        return $this->meter->createObservableCounter($name, $unit, $description, $advisory, ...$callbacks);
    }

    /**
     * Reports measurements for multiple asynchronous instrument from a single callback.
     *
     * The callback receives an {@link ObserverInterface} for each instrument. All provided
     * instruments have to be created by this meter.
     *
     * ```php
     * $callback = $meter->batchObserve(
     *     function(
     *         ObserverInterface $usageObserver,
     *         ObserverInterface $pressureObserver,
     *     ): void {
     *         [$usage, $pressure] = expensive_system_call();
     *         $usageObserver->observe($usage);
     *         $pressureObserver->observe($pressure);
     *     },
     *     $meter->createObservableCounter('usage', description: 'count of items used'),
     *     $meter->createObservableGauge('pressure', description: 'force per unit area'),
     * );
     * ```
     *
     * @param  callable  $callback  function responsible for reporting the measurements
     * @param  AsynchronousInstrument  $instrument  first instrument to report measurements for
     * @param  AsynchronousInstrument  ...$instruments  additional instruments to report measurements for
     * @return ObservableCallbackInterface token to detach callback
     *
     * @see https://opentelemetry.io/docs/specs/otel/metrics/api/#multiple-instrument-callbacks
     */
    public function batchObserve(callable $callback, AsynchronousInstrument $instrument, AsynchronousInstrument ...$instruments): ObservableCallbackInterface
    {
        return $this->meter->batchObserve($callback, $instrument, ...$instruments);
    }

    /**
     * Creates a `Histogram`.
     *
     * @param  string  $name  name of the instrument
     * @param  string|null  $unit  unit of measure
     * @param  string|null  $description  description of the instrument
     * @param  array  $advisory  an optional set of recommendations
     * @return HistogramInterface created instrument
     *
     * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/metrics/api.md#histogram-creation
     */
    public function createHistogram(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): HistogramInterface
    {
        return $this->meter->createHistogram($name, $unit, $description, $advisory);
    }

    /**
     * Creates a `Gauge`.
     *
     * @param  string  $name  name of the instrument
     * @param  string|null  $unit  unit of measure
     * @param  string|null  $description  description of the instrument
     * @param  array  $advisory  an optional set of recommendations
     * @return GaugeInterface created instrument
     *
     * @see https://opentelemetry.io/docs/specs/otel/metrics/api/#gauge-creation
     *
     * @experimental
     */
    public function createGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): GaugeInterface
    {
        return $this->meter->createGauge($name, $unit, $description, $advisory);
    }

    /**
     * Creates an `ObservableGauge`.
     *
     * @param  string  $name  name of the instrument
     * @param  string|null  $unit  unit of measure
     * @param  string|null  $description  description of the instrument
     * @param  array  $advisory  an optional set of recommendations
     * @param  callable  ...$callbacks  responsible for reporting measurements
     * @return ObservableGaugeInterface created instrument
     *
     * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/metrics/api.md#asynchronous-gauge-creation
     */
    public function createObservableGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = [], callable ...$callbacks): ObservableGaugeInterface
    {
        return $this->meter->createObservableGauge($name, $unit, $description, $advisory, ...$callbacks);
    }

    /**
     * Creates an `UpDownCounter`.
     *
     * @param  string  $name  name of the instrument
     * @param  string|null  $unit  unit of measure
     * @param  string|null  $description  description of the instrument
     * @param  array  $advisory  an optional set of recommendations
     * @return UpDownCounterInterface created instrument
     *
     * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/metrics/api.md#updowncounter-creation
     */
    public function createUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): UpDownCounterInterface
    {
        return $this->meter->createUpDownCounter($name, $unit, $description, $advisory);
    }

    /**
     * Creates an `ObservableUpDownCounter`.
     *
     * @param  string  $name  name of the instrument
     * @param  string|null  $unit  unit of measure
     * @param  string|null  $description  description of the instrument
     * @param  array  $advisory  an optional set of recommendations
     * @param  callable  ...$callbacks  responsible for reporting measurements
     * @return ObservableUpDownCounterInterface created instrument
     *
     * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/metrics/api.md#asynchronous-updowncounter-creation
     */
    public function createObservableUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = [], callable ...$callbacks): ObservableUpDownCounterInterface
    {
        return $this->meter->createObservableUpDownCounter($name, $unit, $description, $advisory, ...$callbacks);
    }

    public function collect(): bool
    {
        return $this->reader->collect();
    }
}
