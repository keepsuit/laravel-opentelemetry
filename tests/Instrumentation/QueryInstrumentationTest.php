<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\QueryInstrumentation;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Metrics\DbMetrics;

beforeEach(function () {
    registerInstrumentation(QueryInstrumentation::class);
});

test('query span is not created when trace is not started', function () {
    expect(Tracer::traceStarted())->toBeFalse();

    DB::table('users')
        ->where('id', 1)
        ->where('name', 'like', 'John%')
        ->get();

    $span = getRecordedSpans()->first();

    expect($span)->toBeNull();
});

it('can record query span', function () {
    withRootSpan(function () {
        DB::table('users')->get();
    });

    $span = getRecordedSpans()->first();

    expect($span)
        ->toBeInstanceOf(ImmutableSpan::class)
        ->getName()->toBe('SELECT :memory:')
        ->getKind()->toBe(SpanKind::KIND_CLIENT)
        ->getAttributes()->toArray()->toBe([
            'db.system.name' => 'sqlite',
            'db.namespace' => ':memory:',
            'db.operation.name' => 'SELECT',
            'db.query.text' => 'select * from "users"',
        ])
        ->hasEnded()->toBeTrue()
        ->getEndEpochNanos()->toBeLessThan(Clock::getDefault()->now());

    expect($span->getEndEpochNanos() - $span->getStartEpochNanos())
        ->toBeGreaterThan(0);
});

it('can record a query with bindings', function () {
    withRootSpan(function () {
        DB::table('users')
            ->where('id', 1)
            ->where('name', 'like', 'John%')
            ->get();
    });

    $span = getRecordedSpans()->first();

    expect($span)
        ->toBeInstanceOf(ImmutableSpan::class)
        ->getName()->toBe('SELECT :memory:')
        ->getKind()->toBe(SpanKind::KIND_CLIENT)
        ->getAttributes()->toArray()->toBe([
            'db.system.name' => 'sqlite',
            'db.namespace' => ':memory:',
            'db.operation.name' => 'SELECT',
            'db.query.text' => 'select * from "users" where "id" = ? and "name" like ?',
        ]);
});

it('can record a query with named bindings', function () {
    DB::table('users')->insert([
        'name' => 'John Doe',
        'admin' => true,
    ]);

    withRootSpan(function () {
        DB::statement(<<<'SQL'
    update "users" set "name" = :name where admin = true
    SQL, [
            'name' => 'Admin',
        ]);
    });

    $span = getRecordedSpans()->first();

    expect($span)
        ->toBeInstanceOf(ImmutableSpan::class)
        ->getName()->toBe('UPDATE :memory:')
        ->getKind()->toBe(SpanKind::KIND_CLIENT)
        ->getAttributes()->toArray()->toBe([
            'db.system.name' => 'sqlite',
            'db.namespace' => ':memory:',
            'db.operation.name' => 'UPDATE',
            'db.query.text' => 'update "users" set "name" = :name where admin = true',
        ]);
});

it('can record query duration metric', function () {
    withRootSpan(function () {
        DB::table('users')->get();
    });

    $metric = getRecordedMetrics()->firstWhere('name', DbMetrics::DB_CLIENT_OPERATION_DURATION);

    expect($metric)
        ->toBeInstanceOf(Metric::class)
        ->unit->toBe('s')
        ->data->toBeInstanceOf(Histogram::class);

    /** @var HistogramDataPoint $dataPoint */
    $dataPoint = $metric->data->dataPoints[0];

    expect($dataPoint->attributes)
        ->toMatchArray([
            DbAttributes::DB_SYSTEM_NAME => 'sqlite',
            DbAttributes::DB_NAMESPACE => ':memory:',
            DbAttributes::DB_OPERATION_NAME => 'SELECT',
        ]);

    expect($dataPoint->attributes->has(DbAttributes::DB_QUERY_TEXT))->toBeFalse();
});

it('maps database drivers to well-known db systems', function (string $driver, string $expected) {
    $event = new QueryExecuted('select 1', [], 1.0, new Connection(fn () => null, 'db', '', ['driver' => $driver]));

    expect(invade(new QueryInstrumentation)->sharedTraceMetricAttributes($event, 'SELECT'))
        ->toHaveKey(DbAttributes::DB_SYSTEM_NAME, $expected);
})->with([
    ['pgsql', 'postgresql'],
    ['sqlsrv', 'microsoft.sql_server'],
    ['mysql', 'mysql'],
]);

it('records server address and non-default port', function (array $config, ?string $address, ?int $port) {
    $event = new QueryExecuted('select 1', [], 1.0, new Connection(fn () => null, 'db', '', $config));

    $attributes = invade(new QueryInstrumentation)->sharedTraceMetricAttributes($event, 'SELECT');

    expect($attributes[ServerAttributes::SERVER_ADDRESS])->toBe($address)
        ->and($attributes[ServerAttributes::SERVER_PORT])->toBe($port);
})->with([
    'mysql default port' => [['driver' => 'mysql', 'host' => 'db.local', 'port' => '3306'], 'db.local', null],
    'mariadb default port' => [['driver' => 'mariadb', 'host' => 'db.local', 'port' => 3306], 'db.local', null],
    'pgsql default port' => [['driver' => 'pgsql', 'host' => 'db.local', 'port' => '5432'], 'db.local', null],
    'sqlsrv default port' => [['driver' => 'sqlsrv', 'host' => 'db.local', 'port' => '1433'], 'db.local', null],
    'custom port' => [['driver' => 'mysql', 'host' => 'db.local', 'port' => '3307'], 'db.local', 3307],
    'multiple hosts' => [['driver' => 'mysql', 'host' => ['db1.local', 'db2.local']], 'db1.local,db2.local', null],
    'sqlite' => [['driver' => 'sqlite'], null, null],
]);
