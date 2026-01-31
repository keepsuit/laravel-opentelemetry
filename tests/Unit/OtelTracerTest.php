<?php

use Composer\InstalledVersions;
use Keepsuit\LaravelOpenTelemetry\Support\PropagatorBuilder;
use Keepsuit\LaravelOpenTelemetry\Support\SamplerBuilder;

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

it('can register sampler', function () {
    expect(SamplerBuilder::new()->build('always_on'))
        ->toBeInstanceOf(\OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler::class);

    expect(SamplerBuilder::new()->build('always_off'))
        ->toBeInstanceOf(\OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler::class);

    $instance = SamplerBuilder::new()->build('traceidratio', args: ['ratio' => 0.5]);
    expect($instance)
        ->toBeInstanceOf(\OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler::class);
    expect(invade($instance)->probability)->toBe(0.5);
});

it('can register parent based sampler', function () {
    expect(invade(SamplerBuilder::new()->build('always_on', true)))
        ->obj->toBeInstanceOf(\OpenTelemetry\SDK\Trace\Sampler\ParentBased::class)
        ->root->toBeInstanceOf(\OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler::class);

    expect(invade(SamplerBuilder::new()->build('always_off', true)))
        ->obj->toBeInstanceOf(\OpenTelemetry\SDK\Trace\Sampler\ParentBased::class)
        ->root->toBeInstanceOf(\OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler::class);

    $instance = SamplerBuilder::new()->build('traceidratio', true, ['ratio' => 0.5]);
    expect(invade($instance))
        ->obj->toBeInstanceOf(\OpenTelemetry\SDK\Trace\Sampler\ParentBased::class)
        ->root->toBeInstanceOf(\OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler::class);
    expect(invade(invade($instance)->root)->probability)->toBe(0.5);
});
