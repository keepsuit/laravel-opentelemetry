<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\QueryInstrumentation;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Metrics\DbMetrics;

beforeEach(function () {
    registerInstrumentation(QueryInstrumentation::class);

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->boolean('admin')->default(false);
    });
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
        ->getName()->toBe('SELECT')
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
        ->getName()->toBe('SELECT')
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
        ->getName()->toBe('UPDATE')
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
        ->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class)
        ->unit->toBe('s')
        ->data->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Histogram::class);

    /** @var \OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint $dataPoint */
    $dataPoint = $metric->data->dataPoints[0];

    expect($dataPoint->attributes)
        ->toMatchArray([
            DbAttributes::DB_SYSTEM_NAME => 'sqlite',
            DbAttributes::DB_NAMESPACE => ':memory:',
            DbAttributes::DB_OPERATION_NAME => 'SELECT',
            DbAttributes::DB_QUERY_TEXT => 'select * from "users"',
        ]);
});
