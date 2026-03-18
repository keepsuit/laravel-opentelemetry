<?php

use Composer\InstalledVersions;
use Keepsuit\LaravelOpenTelemetry\Support\PropagatorBuilder;
use Keepsuit\LaravelOpenTelemetry\Support\SamplerBuilder;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use OpenTelemetry\Extension\Propagator\B3\B3Propagator;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\Tracer;

it('can build open telemetry tracer', function () {
    /** @var Tracer $tracer */
    $tracer = app(TracerInterface::class);

    expect($tracer)
        ->toBeInstanceOf(Tracer::class);

    expect($tracer->getInstrumentationScope())
        ->getName()->toBe('laravel-opentelemetry')
        ->getVersion()->toBe(InstalledVersions::getPrettyVersion('keepsuit/laravel-opentelemetry'));
});

it('can register multiple propagators', function () {
    $propagator = PropagatorBuilder::new()->build('tracecontext,baggage,b3');

    expect($propagator)
        ->toBeInstanceOf(MultiTextMapPropagator::class)
        ->fields()->toBe(['traceparent', 'tracestate', 'baggage', 'b3']);

    $propagators = invade($propagator)->propagators;

    expect($propagators[0])->toBeInstanceOf(TraceContextPropagator::class);
    expect($propagators[1])->toBeInstanceOf(BaggagePropagator::class);
    expect($propagators[2])->toBeInstanceOf(B3Propagator::class);
});

it('register noop propagator when empty or invalid', function () {
    expect(PropagatorBuilder::new()->build(''))
        ->toBeInstanceOf(NoopTextMapPropagator::class);

    expect(PropagatorBuilder::new()->build('invalid'))
        ->toBeInstanceOf(NoopTextMapPropagator::class);
});

it('can register sampler', function () {
    expect(SamplerBuilder::new()->build('always_on'))
        ->toBeInstanceOf(AlwaysOnSampler::class);

    expect(SamplerBuilder::new()->build('always_off'))
        ->toBeInstanceOf(AlwaysOffSampler::class);

    $instance = SamplerBuilder::new()->build('traceidratio', args: ['ratio' => 0.5]);
    expect($instance)
        ->toBeInstanceOf(TraceIdRatioBasedSampler::class);
    expect(invade($instance)->probability)->toBe(0.5);
});

it('can register parent based sampler', function () {
    expect(invade(SamplerBuilder::new()->build('always_on', true)))
        ->obj->toBeInstanceOf(ParentBased::class)
        ->root->toBeInstanceOf(AlwaysOnSampler::class);

    expect(invade(SamplerBuilder::new()->build('always_off', true)))
        ->obj->toBeInstanceOf(ParentBased::class)
        ->root->toBeInstanceOf(AlwaysOffSampler::class);

    $instance = SamplerBuilder::new()->build('traceidratio', true, ['ratio' => 0.5]);
    expect(invade($instance))
        ->obj->toBeInstanceOf(ParentBased::class)
        ->root->toBeInstanceOf(TraceIdRatioBasedSampler::class);
    expect(invade(invade($instance)->root)->probability)->toBe(0.5);
});
