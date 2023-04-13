<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

it('records cache hits', function () {
    withRootSpan(function () {
        Cache::remember('test-hit', 60, fn () => 'test');
        Cache::get('test-hit');
    });

    $rootSpan = Arr::last(getRecordedSpans());

    /** @var \OpenTelemetry\SDK\Trace\Event $event */
    $event = Arr::last($rootSpan->getEvents());

    expect($event)
        ->not->toBeNull()
        ->getName()->toBe('cache hit')
        ->getAttributes()->toArray()->toBe([
            'key' => 'test-hit',
            'tags' => '[]',
        ]);
});

it('records cache miss', function () {
    withRootSpan(function () {
        Cache::get('test-miss');
    });

    $rootSpan = Arr::last(getRecordedSpans());

    /** @var \OpenTelemetry\SDK\Trace\Event $event */
    $event = Arr::last($rootSpan->getEvents());

    expect($event)
        ->not->toBeNull()
        ->getName()->toBe('cache miss')
        ->getAttributes()->toArray()->toBe([
            'key' => 'test-miss',
            'tags' => '[]',
        ]);
});

it('records cache put without a ttl', function () {
    withRootSpan(function () {
        Cache::put('test-put', 'test');
    });

    $rootSpan = Arr::last(getRecordedSpans());

    /** @var \OpenTelemetry\SDK\Trace\Event $event */
    $event = Arr::last($rootSpan->getEvents());

    expect($event)
        ->not->toBeNull()
        ->getName()->toBe('cache set')
        ->getAttributes()->toArray()->toBe([
            'key' => 'test-put',
            'tags' => '[]',
            'expires_at' => 'never',
            'expires_in_seconds' => 'never',
            'expires_in_human' => 'never',
        ]);
});

it('records cache put with a ttl', function () {
    $expiredAt = now()->addSeconds(60);

    withRootSpan(function () use ($expiredAt) {
        Cache::put('test-put', 'test', $expiredAt);
    });

    $rootSpan = Arr::last(getRecordedSpans());

    /** @var \OpenTelemetry\SDK\Trace\Event $event */
    $event = Arr::last($rootSpan->getEvents());

    expect($event)
        ->not->toBeNull()
        ->getName()->toBe('cache set')
        ->getAttributes()->toArray()->toBe([
            'key' => 'test-put',
            'tags' => '[]',
            'expires_at' => $expiredAt->getTimestamp(),
            'expires_in_seconds' => 60,
            'expires_in_human' => '59 seconds from now',
        ]);
});

it('records cache forget', function () {
    withRootSpan(function () {
        Cache::put('test-forget', 'test');
        Cache::forget('test-forget');
    });

    $rootSpan = Arr::last(getRecordedSpans());

    /** @var \OpenTelemetry\SDK\Trace\Event $event */
    $event = Arr::last($rootSpan->getEvents());

    expect($event)
        ->not->toBeNull()
        ->getName()->toBe('cache forget')
        ->getAttributes()->toArray()->toBe([
            'key' => 'test-forget',
            'tags' => '[]',
        ]);
});
