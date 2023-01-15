<?php

use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\Span;

it('can resolve laravel tracer', function () {
    /** @var \Keepsuit\LaravelOpenTelemetry\Tracer $tracer */
    $tracer = app(\Keepsuit\LaravelOpenTelemetry\Tracer::class);

    expect($tracer)
        ->toBeInstanceOf(\Keepsuit\LaravelOpenTelemetry\Tracer::class)
        ->isRecording()->toBeTrue()
        ->traceId()->toBe('00000000000000000000000000000000')
        ->activeSpan()->toBeInstanceOf(\OpenTelemetry\API\Trace\NonRecordingSpan::class);
});

it('can measure a span', function () {
    $span = Tracer::start('test span');

    assert($span instanceof Span);
    expect($span)
        ->getName()->toBe('test span')
        ->isRecording()->toBeTrue()
        ->hasEnded()->toBeFalse()
        ->getKind()->toBe(SpanKind::KIND_INTERNAL);

    $span->end();

    expect($span)
        ->isRecording()->toBeFalse()
        ->hasEnded()->toBeTrue();
});
