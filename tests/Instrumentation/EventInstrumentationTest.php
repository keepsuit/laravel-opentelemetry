<?php

use Illuminate\Support\Arr;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\EventInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Tests\Support\TestEvent;

it('records string event', function () {
    registerInstrumentation(EventInstrumentation::class);

    withRootSpan(function () {
        event('test-event', 'value');
    });

    $rootSpan = getRecordedSpans()->last();

    /** @var \OpenTelemetry\SDK\Trace\Event $event */
    $event = Arr::last($rootSpan->getEvents());

    expect($event)
        ->not->toBeNull()
        ->getName()->toBe('event fired')
        ->getAttributes()->toArray()->toBe([
            'event' => 'test-event',
        ]);
});

it('records class event', function () {
    registerInstrumentation(EventInstrumentation::class);

    withRootSpan(function () {
        event(new TestEvent('test'));
    });

    $rootSpan = getRecordedSpans()->last();

    /** @var \OpenTelemetry\SDK\Trace\Event $event */
    $event = Arr::last($rootSpan->getEvents());

    expect($event)
        ->not->toBeNull()
        ->getName()->toBe(sprintf('event fired', TestEvent::class))
        ->getAttributes()->toArray()->toBe([
            'event' => TestEvent::class,
        ]);
});

it('can ignore events', function () {
    registerInstrumentation(EventInstrumentation::class, [
        'excluded' => ['test-event'],
    ]);

    withRootSpan(function () {
        event('test-event', 'value');
    });

    $rootSpan = getRecordedSpans()->last();

    /** @var \OpenTelemetry\SDK\Trace\Event $event */
    $event = Arr::last($rootSpan->getEvents());

    expect($event)->toBeNull();
});
