<?php

use Illuminate\Support\Facades\Redis;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\RedisInstrumentation;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Metrics\DbMetrics;

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
            'db.query.text' => 'GET ?',
            'server.address' => $config['host'],
        ])
        ->hasEnded()->toBeTrue()
        ->getEndEpochNanos()->toBeLessThan(Clock::getDefault()->now());

    expect($span->getEndEpochNanos() - $span->getStartEpochNanos())
        ->toBeGreaterThan(0);

    $metric = getRecordedMetrics()->firstWhere('name', DbMetrics::DB_CLIENT_OPERATION_DURATION);

    expect($metric)
        ->toBeInstanceOf(Metric::class)
        ->unit->toBe('s')
        ->data->toBeInstanceOf(Histogram::class);
})->with([
    'predis',
    'phpredis',
]);

it('can record redis call duration metric', function (string $client) {
    config()->set('database.redis.client', $client);
    $config = config('database.redis.default');

    withRootSpan(fn () => Redis::connection('default')->get('test'));

    $metric = getRecordedMetrics()->firstWhere('name', DbMetrics::DB_CLIENT_OPERATION_DURATION);

    expect($metric)
        ->toBeInstanceOf(Metric::class)
        ->unit->toBe('s')
        ->data->toBeInstanceOf(Histogram::class);

    /** @var HistogramDataPoint $dataPoint */
    $dataPoint = $metric->data->dataPoints[0];

    expect($dataPoint->attributes)
        ->toMatchArray([
            DbAttributes::DB_SYSTEM_NAME => 'redis',
            DbAttributes::DB_NAMESPACE => '0',
            DbAttributes::DB_OPERATION_NAME => 'GET',
        ]);

    expect($dataPoint->attributes->has(DbAttributes::DB_QUERY_TEXT))->toBeFalse();
})->with([
    'predis',
    'phpredis',
]);

it('sanitizes redis query text', function (string $client) {
    config()->set('database.redis.client', $client);

    withRootSpan(function () {
        Redis::connection('default')->set('session:abc123', 'secret');
        Redis::connection('default')->hmset('myhash', ['field1' => 'secret1', 'field2' => 'secret2']);
    });

    expect(getRecordedSpans()->take(2)->map(fn (ImmutableSpan $span) => $span->getAttributes()->get(DbAttributes::DB_QUERY_TEXT))->all())
        ->toBe(['SET ? ?', 'HMSET ? ? ? ? ?']);
})->with([
    'predis',
    'phpredis',
]);

it('resolves no db index for an unnamed connection', function () {
    expect(invade(new RedisInstrumentation)->resolveDbIndex(null))->toBeNull();
});

it('records failed redis commands', function (string $client) {
    config()->set('database.redis.client', $client);

    $exception = null;

    withRootSpan(function () use (&$exception) {
        try {
            Redis::connection('default')->command('notacommand');
        } catch (Throwable $e) {
            $exception = $e;
        }
    });

    expect($exception)->not->toBeNull();

    $span = getRecordedSpans()->first();

    expect($span)
        ->getName()->toBe('NOTACOMMAND')
        ->getStatus()->getCode()->toBe(StatusCode::STATUS_ERROR)
        ->getAttributes()->toArray()->toMatchArray([
            'db.system.name' => 'redis',
            'db.operation.name' => 'NOTACOMMAND',
            'db.query.text' => 'NOTACOMMAND',
            'error.type' => $exception::class,
        ]);

    expect($span->getEvents())->toHaveCount(1)
        ->and($span->getEvents()[0]->getName())->toBe('exception');

    /** @var HistogramDataPoint $dataPoint */
    $dataPoint = getRecordedMetrics()->firstWhere('name', DbMetrics::DB_CLIENT_OPERATION_DURATION)->data->dataPoints[0];

    expect($dataPoint->attributes->get(ErrorAttributes::ERROR_TYPE))->toBe($exception::class);
})->with([
    'predis',
    'phpredis',
]);

it('records non-default server port', function () {
    config()->set('database.redis.client', 'predis');
    config()->set('database.redis.unreachable', [
        'host' => '127.0.0.1',
        'port' => 1,
        'database' => '0',
    ]);

    withRootSpan(fn () => rescue(fn () => Redis::connection('unreachable')->get('test'), report: false));

    expect(getRecordedSpans()->first()->getAttributes()->toArray())
        ->toMatchArray([
            'server.address' => '127.0.0.1',
            'server.port' => 1,
        ]);

    /** @var HistogramDataPoint $dataPoint */
    $dataPoint = getRecordedMetrics()->firstWhere('name', DbMetrics::DB_CLIENT_OPERATION_DURATION)->data->dataPoints[0];

    expect($dataPoint->attributes->get(ServerAttributes::SERVER_PORT))->toBe(1);
});
