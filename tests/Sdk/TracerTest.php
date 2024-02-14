<?php

use Composer\InstalledVersions;
use Keepsuit\LaravelOpenTelemetry\Support\PropagatorBuilder;

it('can build open telemetry tracer', function () {
    /** @var \OpenTelemetry\SDK\Trace\Tracer $tracer */
    $tracer = app(\OpenTelemetry\API\Trace\TracerInterface::class);

    expect($tracer)
        ->toBeInstanceOf(\OpenTelemetry\SDK\Trace\Tracer::class);

    expect($tracer->getInstrumentationScope())
        ->getName()->toBe('laravel-opentelemetry')
        ->getVersion()->toBe(InstalledVersions::getPrettyVersion('keepsuit/laravel-opentelemetry'));
});

it('can register multiple propagators', function () {
    $propagator = PropagatorBuilder::new()->build('tracecontext,baggage,b3');

    expect($propagator)
        ->toBeInstanceOf(\OpenTelemetry\Context\Propagation\MultiTextMapPropagator::class)
        ->fields()->toBe(['traceparent', 'tracestate', 'baggage', 'b3']);

    $propagators = invade($propagator)->propagators;

    expect($propagators[0])->toBeInstanceOf(\OpenTelemetry\API\Trace\Propagation\TraceContextPropagator::class);
    expect($propagators[1])->toBeInstanceOf(\OpenTelemetry\API\Baggage\Propagation\BaggagePropagator::class);
    expect($propagators[2])->toBeInstanceOf(\OpenTelemetry\Extension\Propagator\B3\B3Propagator::class);
});

it('register noop propagator when empty or invalid', function () {
    expect(PropagatorBuilder::new()->build(''))
        ->toBeInstanceOf(\OpenTelemetry\Context\Propagation\NoopTextMapPropagator::class);

    expect(PropagatorBuilder::new()->build('invalid'))
        ->toBeInstanceOf(\OpenTelemetry\Context\Propagation\NoopTextMapPropagator::class);
});
