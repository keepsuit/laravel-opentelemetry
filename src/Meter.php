<?php

namespace Keepsuit\LaravelOpenTelemetry;

use OpenTelemetry\API\Metrics\AsynchronousInstrument;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\GaugeInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\Instrument;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObservableCounterInterface;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use OpenTelemetry\SDK\Metrics\MetricReaderInterface;

class Meter
{
    /**
     * @var array<string, Instrument>
     */
    protected array $instruments = [];

    public function __construct(
        protected MeterInterface $meter,
        protected MetricReaderInterface $reader
    ) {}

    /**
     * Get or create a `Counter` instrument.
     *
     * @param  string  $name  name of the instrument
     * @param  ?string  $unit  unit of measure
     * @param  ?string  $description  description of the instrument
     * @param  array  $advisory  an optional set of recommendations
     * @return CounterInterface created instrument
     *
     * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/metrics/api.md#counter-creation
     */
    public function counter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): CounterInterface
    {
        if ($existing = $this->resolveExistingInstrument($name, CounterInterface::class)) {
            return $existing;
        }

        $counter = $this->meter->createCounter($name, $unit, $description, $advisory);

        $this->instruments[$name] = $counter;

        return $counter;
    }

    /**
     * Get or create a `Gauge` instrument.
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
    public function gauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): GaugeInterface
    {
        if ($existing = $this->resolveExistingInstrument($name, GaugeInterface::class)) {
            return $existing;
        }

        $gauge = $this->meter->createGauge($name, $unit, $description, $advisory);

        $this->instruments[$name] = $gauge;

        return $gauge;
    }

    /**
     * Get or create a `Histogram` instrument.
     *
     * @param  string  $name  name of the instrument
     * @param  string|null  $unit  unit of measure
     * @param  string|null  $description  description of the instrument
     * @param  array{ExplicitBucketBoundaries?: float[]}  $advisory  an optional set of recommendations
     * @return HistogramInterface created instrument
     *
     * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/metrics/api.md#histogram-creation
     */
    public function histogram(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): HistogramInterface
    {
        if ($existing = $this->resolveExistingInstrument($name, HistogramInterface::class)) {
            return $existing;
        }

        $histogram = $this->meter->createHistogram($name, $unit, $description, $advisory);

        $this->instruments[$name] = $histogram;

        return $histogram;
    }

    /**
     * Get or create an `UpDownCounter` instrument.
     *
     * @param  string  $name  name of the instrument
     * @param  string|null  $unit  unit of measure
     * @param  string|null  $description  description of the instrument
     * @param  array  $advisory  an optional set of recommendations
     * @return UpDownCounterInterface created instrument
     *
     * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/metrics/api.md#updowncounter-creation
     */
    public function upDownCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): UpDownCounterInterface
    {
        if ($existing = $this->resolveExistingInstrument($name, UpDownCounterInterface::class)) {
            return $existing;
        }

        $upDownCounter = $this->meter->createUpDownCounter($name, $unit, $description, $advisory);

        $this->instruments[$name] = $upDownCounter;

        return $upDownCounter;
    }

    /**
     * Get or create an `ObservableCounter` instrument.
     *
     * @param  string  $name  name of the instrument
     * @param  ?string  $unit  unit of measure
     * @param  ?string  $description  description of the instrument
     * @param  array  $advisory  an optional set of recommendations
     * @return ObservableCounterInterface created instrument
     *
     * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/metrics/api.md#asynchronous-counter-creation
     */
    public function observableCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): ObservableCounterInterface
    {
        if ($existing = $this->resolveExistingInstrument($name, ObservableCounterInterface::class)) {
            return $existing;
        }

        $observableCounter = $this->meter->createObservableCounter($name, $unit, $description, $advisory);

        $this->instruments[$name] = $observableCounter;

        return $observableCounter;
    }

    /**
     * Get or create an `ObservableGauge` instrument.
     *
     * @param  string  $name  name of the instrument
     * @param  string|null  $unit  unit of measure
     * @param  string|null  $description  description of the instrument
     * @param  array  $advisory  an optional set of recommendations
     * @return ObservableGaugeInterface created instrument
     *
     * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/metrics/api.md#asynchronous-gauge-creation
     */
    public function observableGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): ObservableGaugeInterface
    {
        if ($existing = $this->resolveExistingInstrument($name, ObservableGaugeInterface::class)) {
            return $existing;
        }

        $observableGauge = $this->meter->createObservableGauge($name, $unit, $description, $advisory);

        $this->instruments[$name] = $observableGauge;

        return $observableGauge;
    }

    /**
     * Get or create an `ObservableUpDownCounter`.
     *
     * @param  string  $name  name of the instrument
     * @param  string|null  $unit  unit of measure
     * @param  string|null  $description  description of the instrument
     * @param  array  $advisory  an optional set of recommendations
     * @return ObservableUpDownCounterInterface created instrument
     *
     * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/metrics/api.md#asynchronous-updowncounter-creation
     */
    public function observableUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): ObservableUpDownCounterInterface
    {
        if ($existing = $this->resolveExistingInstrument($name, ObservableUpDownCounterInterface::class)) {
            return $existing;
        }

        $observableUpDownCounter = $this->meter->createObservableUpDownCounter($name, $unit, $description, $advisory);

        $this->instruments[$name] = $observableUpDownCounter;

        return $observableUpDownCounter;
    }

    /**
     * Reports measurements for multiple asynchronous instrument from a single callback.
     *
     * The callback receives an {@link ObserverInterface} for each instrument. All provided
     * instruments have to be created by this meter.
     *
     * ```php
     * $callback = Meter::batchObserve([
     *      Meter::observableCounter('usage', description: 'count of items used'),
     *      Meter::observableGauge('pressure', description: 'force per unit area'),
     *     ], function(
     *         ObserverInterface $usageObserver,
     *         ObserverInterface $pressureObserver,
     *     ): void {
     *         [$usage, $pressure] = expensive_system_call();
     *         $usageObserver->observe($usage);
     *         $pressureObserver->observe($pressure);
     *     },
     * );
     * ```
     *
     * @param  AsynchronousInstrument[]  $instruments  instruments to report measurements for
     * @param  callable  $callback  function responsible for reporting the measurements
     * @return ObservableCallbackInterface token to detach callback
     *
     * @see https://opentelemetry.io/docs/specs/otel/metrics/api/#multiple-instrument-callbacks
     */
    public function batchObserve(array $instruments, callable $callback): ObservableCallbackInterface
    {
        return $this->meter->batchObserve($callback, ...$instruments);
    }

    public function collect(): bool
    {
        return $this->reader->collect();
    }

    /**
     * @template T of Instrument
     *
     * @param  class-string<T>  $instrumentClass
     * @return T|null
     */
    protected function resolveExistingInstrument(string $name, string $instrumentClass): ?Instrument
    {
        if (! isset($this->instruments[$name])) {
            return null;
        }

        $existing = $this->instruments[$name];

        if ($existing instanceof $instrumentClass) {
            return $existing;
        }

        throw new \RuntimeException("Instrument with name '{$name}' already exists as a different type.");
    }
}
