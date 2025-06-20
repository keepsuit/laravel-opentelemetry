<?php

use Keepsuit\LaravelOpenTelemetry\Instrumentation\ViewInstrumentation;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\ImmutableSpan;

beforeEach(function () {
    registerInstrumentation(ViewInstrumentation::class);
});

it('can watch view rendering', function () {
    withRootSpan(function () {
        view('simple')->render();
    });

    $span = getRecordedSpans()->first();

    expect($span)
        ->toBeInstanceOf(ImmutableSpan::class)
        ->getName()->toBe('view render')
        ->getKind()->toBe(SpanKind::KIND_INTERNAL)
        ->getAttributes()->toArray()->toBe([
            'template.name' => 'simple',
            'template.engine' => 'blade',
        ]);
});

it('can watch view with partials', function () {
    withRootSpan(function () {
        view('with-partials')->render();
    });

    $partial = getRecordedSpans()->get(0);
    $root = getRecordedSpans()->get(1);

    expect($partial)
        ->toBeInstanceOf(ImmutableSpan::class)
        ->getName()->toBe('view render')
        ->getKind()->toBe(SpanKind::KIND_INTERNAL)
        ->getAttributes()->toArray()->toBe([
            'template.name' => 'simple',
            'template.engine' => 'blade',
        ]);

    expect($root)
        ->toBeInstanceOf(ImmutableSpan::class)
        ->getName()->toBe('view render')
        ->getKind()->toBe(SpanKind::KIND_INTERNAL)
        ->getAttributes()->toArray()->toBe([
            'template.name' => 'with-partials',
            'template.engine' => 'blade',
        ]);
});
