<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Keepsuit\LaravelOpenTelemetry\Tests\Support\TestJob;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SemConv\TraceAttributes;
use Spatie\Valuestore\Valuestore;

beforeEach(function () {
    $this->valuestore = Valuestore::make(__DIR__.'/testJob.json')->flush();

    Schema::create('users', function (Blueprint $table) {
        $table->id();
    });
});

afterEach(function () {
    $this->valuestore->flush();
});

it('can trace queue jobs', function () {
    withRootSpan(function () {
        dispatch(new TestJob($this->valuestore));
    });

    Artisan::call('queue:work', [
        '--once' => true,
    ]);

    $root = getRecordedSpans()->first(fn (ImmutableSpan $span) => $span->getName() === 'root');
    $enqueueSpan = getRecordedSpans()->first(fn (ImmutableSpan $span) => $span->getName() === TestJob::class.' enqueue');
    $processSpan = getRecordedSpans()->first(fn (ImmutableSpan $span) => $span->getName() === TestJob::class.' process');

    assert($root instanceof ImmutableSpan);
    assert($enqueueSpan instanceof ImmutableSpan);
    assert($processSpan instanceof ImmutableSpan);

    $traceId = $enqueueSpan->getTraceId();
    $spanId = $enqueueSpan->getSpanId();

    expect($traceId)
        ->not->toBeEmpty()
        ->not->toBe('00000000000000000000000000000000');

    expect($spanId)
        ->not->toBeEmpty()
        ->not->toBe('0000000000000000');

    expect($this->valuestore)
        ->get('uuid')->not->toBeNull()
        ->get('traceparentInJob')->toBe(sprintf('00-%s-%s-01', $traceId, $spanId))
        ->get('traceIdInJob')->toBe($traceId)
        ->get('logContextInJob')->toMatchArray(['traceId' => $traceId]);

    expect($enqueueSpan)
        ->getAttributes()->toMatchArray([
            TraceAttributes::MESSAGING_SYSTEM => 'redis',
            TraceAttributes::MESSAGING_OPERATION => 'enqueue',
            TraceAttributes::MESSAGE_ID => $this->valuestore->get('uuid'),
            TraceAttributes::MESSAGING_DESTINATION_NAME => 'default',
            TraceAttributes::MESSAGING_DESTINATION_TEMPLATE => TestJob::class,
        ]);

    expect($processSpan)
        ->not->toBeNull()
        ->getAttributes()->toMatchArray([
            TraceAttributes::MESSAGING_SYSTEM => 'redis',
            TraceAttributes::MESSAGING_OPERATION => 'process',
            TraceAttributes::MESSAGE_ID => $this->valuestore->get('uuid'),
            TraceAttributes::MESSAGING_DESTINATION_NAME => 'default',
            TraceAttributes::MESSAGING_DESTINATION_TEMPLATE => TestJob::class,
        ]);
});

it('can trace queue jobs dispatched after commit', function () {
    withRootSpan(function () {
        DB::transaction(function () {
            dispatch(new TestJob($this->valuestore))->afterCommit();

            DB::table('users')->get();
        });
    });

    Artisan::call('queue:work', [
        '--once' => true,
    ]);

    $root = getRecordedSpans()->first(fn (ImmutableSpan $span) => $span->getName() === 'root');
    $sqlSpan = getRecordedSpans()->first(fn (ImmutableSpan $span) => $span->getName() === 'sql SELECT');
    $enqueueSpan = getRecordedSpans()->first(fn (ImmutableSpan $span) => $span->getName() === TestJob::class.' enqueue');
    $processSpan = getRecordedSpans()->first(fn (ImmutableSpan $span) => $span->getName() === TestJob::class.' process');

    assert($root instanceof ImmutableSpan);
    assert($sqlSpan instanceof ImmutableSpan);
    assert($enqueueSpan instanceof ImmutableSpan);
    assert($processSpan instanceof ImmutableSpan);

    expect($enqueueSpan)
        ->getParentSpanId()->toBe($root->getSpanId());

    expect($processSpan)
        ->getParentSpanId()->toBe($enqueueSpan->getSpanId());

    expect($sqlSpan)
        ->getParentSpanId()->toBe($root->getSpanId());

    expect($this->valuestore)
        ->get('uuid')->not->toBeNull()
        ->get('traceparentInJob')->toBe(sprintf('00-%s-%s-01', $root->getTraceId(), $enqueueSpan->getSpanId()))
        ->get('traceIdInJob')->toBe($root->getTraceId())
        ->get('logContextInJob')->toMatchArray(['traceId' => $root->getTraceId()]);
});
