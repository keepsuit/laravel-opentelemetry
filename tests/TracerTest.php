<?php

it('can resolve tracer', function () {
    /** @var \OpenTelemetry\SDK\Trace\Tracer $tracer */
    $tracer = app(\OpenTelemetry\API\Trace\TracerInterface::class);

    expect($tracer)
        ->toBeInstanceOf(\OpenTelemetry\SDK\Trace\Tracer::class);

    expect($tracer->getInstrumentationScope())
        ->getName()->toBe('laravel-opentelemetry')
        ->getVersion()->toBeNull();
});
