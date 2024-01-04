<?php

use Illuminate\Support\Arr;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Trace\ImmutableSpan;

it('can watch a redis call', function () {
    \Illuminate\Support\Facades\Redis::connection('default')->get('test');

    flushSpans();

    $span = Arr::last(getRecordedSpans());
    assert($span instanceof ImmutableSpan);

    expect($span)
        ->getName()->toBe('redis default get')
        ->getKind()->toBe(SpanKind::KIND_CLIENT)
        ->getAttributes()->toArray()->toBe([
            'db.system' => 'redis',
            'db.statement' => 'get test',
            'server.address' => '127.0.0.1',
        ])
        ->hasEnded()->toBeTrue()
        ->getEndEpochNanos()->toBeLessThan(ClockFactory::getDefault()->now());

    expect($span->getEndEpochNanos() - $span->getStartEpochNanos())
        ->toBeGreaterThan(0);
});
