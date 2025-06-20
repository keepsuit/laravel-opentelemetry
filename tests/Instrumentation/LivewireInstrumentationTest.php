<?php

use Keepsuit\LaravelOpenTelemetry\Instrumentation\LivewireInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\ViewInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Tests\Support\LivewireTestComponent;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\ImmutableSpan;

it('can watch livewire component rendering', function () {
    registerInstrumentation(LivewireInstrumentation::class);
    registerInstrumentation(ViewInstrumentation::class);

    withRootSpan(function () {
        \Livewire\Livewire::test(LivewireTestComponent::class);
    });

    $viewSpan = getRecordedSpans()->first();
    $livewireSpan = getRecordedSpans()->get(1);

    expect($livewireSpan)
        ->toBeInstanceOf(ImmutableSpan::class)
        ->getName()->toBe('livewire component')
        ->getKind()->toBe(SpanKind::KIND_INTERNAL)
        ->getAttributes()->toArray()->toHaveKeys(['component.name', 'component.id']);

    expect($viewSpan)
        ->toBeInstanceOf(ImmutableSpan::class)
        ->getName()->toBe('view render')
        ->getKind()->toBe(SpanKind::KIND_INTERNAL);
});
