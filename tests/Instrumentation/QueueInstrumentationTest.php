<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Tests\Support\TestJob;
use OpenTelemetry\API\Trace\StatusCode;
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

test('job enqueue span is not created when trace is not started', function () {
    expect(Tracer::traceStarted())->toBeFalse();

    dispatch(new TestJob($this->valuestore));

    $span = getRecordedSpans()->first();

    expect($span)->toBeNull();
});

test('job process span is created without parent', function () {
    expect(Tracer::traceStarted())->toBeFalse();

    dispatch(new TestJob($this->valuestore));

    Artisan::call('queue:work', [
        '--once' => true,
    ]);

    $root = getRecordedSpans()->last();

    expect($root)
        ->toBeInstanceOf(ImmutableSpan::class)
        ->getName()->toBe(TestJob::class.' process');
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
        ->get('logContextInJob')->toMatchArray(['traceid' => $traceId]);

    expect($enqueueSpan)
        ->toBeInstanceOf(ImmutableSpan::class)
        ->getAttributes()->toMatchArray([
            TraceAttributes::MESSAGING_SYSTEM => 'redis',
            TraceAttributes::MESSAGING_OPERATION_TYPE => 'enqueue',
            TraceAttributes::RPC_MESSAGE_ID => $this->valuestore->get('uuid'),
            TraceAttributes::MESSAGING_DESTINATION_NAME => 'default',
            TraceAttributes::MESSAGING_DESTINATION_TEMPLATE => TestJob::class,
        ]);

    expect($processSpan)
        ->toBeInstanceOf(ImmutableSpan::class)
        ->getTraceId()->toBe($enqueueSpan->getTraceId())
        ->getAttributes()->toMatchArray([
            TraceAttributes::MESSAGING_SYSTEM => 'redis',
            TraceAttributes::MESSAGING_OPERATION_TYPE => 'process',
            TraceAttributes::RPC_MESSAGE_ID => $this->valuestore->get('uuid'),
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
        ->get('logContextInJob')->toMatchArray(['traceid' => $root->getTraceId()]);
});

it('can trace queue failing jobs', function () {
    withRootSpan(function () {
        dispatch(new TestJob($this->valuestore, fail: true));
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

    expect($this->valuestore)
        ->get('uuid')->not->toBeNull()
        ->get('traceparentInJob')->toBe(sprintf('00-%s-%s-01', $traceId, $spanId))
        ->get('traceIdInJob')->toBe($traceId)
        ->get('logContextInJob')->toMatchArray(['traceid' => $traceId]);

    expect($enqueueSpan)
        ->getStatus()->getCode()->toBe(StatusCode::STATUS_UNSET)
        ->getAttributes()->toMatchArray([
            TraceAttributes::MESSAGING_SYSTEM => 'redis',
            TraceAttributes::MESSAGING_OPERATION_TYPE => 'enqueue',
            TraceAttributes::RPC_MESSAGE_ID => $this->valuestore->get('uuid'),
            TraceAttributes::MESSAGING_DESTINATION_NAME => 'default',
            TraceAttributes::MESSAGING_DESTINATION_TEMPLATE => TestJob::class,
        ]);

    expect($processSpan)
        ->not->toBeNull()
        ->getStatus()->getCode()->toBe(StatusCode::STATUS_ERROR)
        ->getEvents()->toHaveCount(1)
        ->getEvents()->{0}->getName()->toBe('exception')
        ->getAttributes()->toMatchArray([
            TraceAttributes::MESSAGING_SYSTEM => 'redis',
            TraceAttributes::MESSAGING_OPERATION_TYPE => 'process',
            TraceAttributes::RPC_MESSAGE_ID => $this->valuestore->get('uuid'),
            TraceAttributes::MESSAGING_DESTINATION_NAME => 'default',
            TraceAttributes::MESSAGING_DESTINATION_TEMPLATE => TestJob::class,
        ]);
});
