<?php

use Illuminate\Support\Arr;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\EventInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Tests\Support\TestEvent;

it('records string event', function () {
    withRootSpan(function () {
        event('test-event', 'value');
    });

    $rootSpan = Arr::last(getRecordedSpans());

    /** @var \OpenTelemetry\SDK\Trace\Event $event */
    $event = Arr::last($rootSpan->getEvents());

    expect($event)
        ->not->toBeNull()
        ->getName()->toBe('Event test-event fired')
        ->getAttributes()->toArray()->toBe([
            'event.name' => 'test-event',
        ]);
});

it('records class event', function () {
    withRootSpan(function () {
        event(new TestEvent('test'));
    });

    $rootSpan = Arr::last(getRecordedSpans());

    /** @var \OpenTelemetry\SDK\Trace\Event $event */
    $event = Arr::last($rootSpan->getEvents());

    expect($event)
        ->not->toBeNull()
        ->getName()->toBe(sprintf('Event %s fired', TestEvent::class))
        ->getAttributes()->toArray()->toBe([
            'event.name' => TestEvent::class,
        ]);
});

it('can ignore events', function () {
    app()->make(EventInstrumentation::class)->register([
        'ignored' => ['test-event'],
    ]);

    withRootSpan(function () {
        event('test-event', 'value');
    });

    $rootSpan = Arr::last(getRecordedSpans());

    /** @var \OpenTelemetry\SDK\Trace\Event $event */
    $event = Arr::last($rootSpan->getEvents());

    expect($event)->toBeNull();
});
