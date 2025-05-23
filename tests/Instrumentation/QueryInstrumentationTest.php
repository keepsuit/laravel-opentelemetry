<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\ImmutableSpan;

beforeEach(function () {
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

it('can watch a query', function () {
    Tracer::newSpan('root')->measure(function () {
        DB::table('users')->get();
    });

    $span = getRecordedSpans()->first();

    expect($span)
        ->toBeInstanceOf(ImmutableSpan::class)
        ->getName()->toBe('sql SELECT')
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

it('can watch a query with bindings', function () {
    Tracer::newSpan('root')->measure(function () {
        DB::table('users')
            ->where('id', 1)
            ->where('name', 'like', 'John%')
            ->get();
    });

    $span = getRecordedSpans()->first();

    expect($span)
        ->toBeInstanceOf(ImmutableSpan::class)
        ->getName()->toBe('sql SELECT')
        ->getKind()->toBe(SpanKind::KIND_CLIENT)
        ->getAttributes()->toArray()->toBe([
            'db.system.name' => 'sqlite',
            'db.namespace' => ':memory:',
            'db.operation.name' => 'SELECT',
            'db.query.text' => 'select * from "users" where "id" = ? and "name" like ?',
        ]);
});

it('can watch a query with named bindings', function () {
    DB::table('users')->insert([
        'name' => 'John Doe',
        'admin' => true,
    ]);

    Tracer::newSpan('root')->measure(function () {
        DB::statement(<<<'SQL'
    update "users" set "name" = :name where admin = true
    SQL, [
            'name' => 'Admin',
        ]);
    });

    $span = getRecordedSpans()->first();

    expect($span)
        ->toBeInstanceOf(ImmutableSpan::class)
        ->getName()->toBe('sql UPDATE')
        ->getKind()->toBe(SpanKind::KIND_CLIENT)
        ->getAttributes()->toArray()->toBe([
            'db.system.name' => 'sqlite',
            'db.namespace' => ':memory:',
            'db.operation.name' => 'UPDATE',
            'db.query.text' => 'update "users" set "name" = :name where admin = true',
        ]);
});
