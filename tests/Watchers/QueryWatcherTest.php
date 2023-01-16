<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Common\Time\ClockFactory;

it('can watch a query', function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    DB::table('users')
        ->get();

    flushSpans();

    $span = Arr::last(getRecordedSpans());
    assert($span instanceof \OpenTelemetry\SDK\Trace\ImmutableSpan);

    expect($span)
        ->getName()->toBe('sqlite :memory:')
        ->getKind()->toBe(SpanKind::KIND_CLIENT)
        ->getAttributes()->toArray()->toMatchArray([
            'db.system' => 'sqlite',
            'db.name' => ':memory:',
            'db.statement' => 'select * from "users"',
        ])
        ->hasEnded()->toBeTrue()
        ->getEndEpochNanos()->toBeLessThan(ClockFactory::getDefault()->now());

    expect($span->getEndEpochNanos() - $span->getStartEpochNanos())
        ->toBeGreaterThan(0);
});
