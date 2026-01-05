<?php

use Illuminate\Support\Facades\Redis;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\RedisInstrumentation;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\ImmutableSpan;

beforeEach(function () {
    registerInstrumentation(RedisInstrumentation::class);
});

test('redis span is not created when trace is not started', function () {
    expect(Tracer::traceStarted())->toBeFalse();

    Redis::connection('default')->get('test');

    $span = getRecordedSpans()->first();

    expect($span)->toBeNull();
});

it('can watch a redis call', function (string $client) {
    config()->set('database.redis.client', $client);
    $config = config('database.redis.default');

    withRootSpan(fn () => Redis::connection('default')->get('test'));

    $span = getRecordedSpans()->first();

    expect($span)
        ->toBeInstanceOf(ImmutableSpan::class)
        ->getName()->toBe('GET')
        ->getKind()->toBe(SpanKind::KIND_CLIENT)
        ->getAttributes()->toArray()->toBe([
            'db.system.name' => 'redis',
            'db.operation.name' => 'GET',
            'db.namespace' => '0',
            'db.query.text' => 'get test',
            'server.address' => $config['host'],
        ])
        ->hasEnded()->toBeTrue()
        ->getEndEpochNanos()->toBeLessThan(Clock::getDefault()->now());

    expect($span->getEndEpochNanos() - $span->getStartEpochNanos())
        ->toBeGreaterThan(0);
})->with([
    'predis',
    'phpredis',
]);
