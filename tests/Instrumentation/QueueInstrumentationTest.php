<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\QueryInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\QueueInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Tests\Support\TestJob;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SemConv\Incubating\Attributes\MessagingIncubatingAttributes;
use Spatie\Valuestore\Valuestore;

beforeEach(function () {
    registerInstrumentation(QueueInstrumentation::class);
    registerInstrumentation(QueryInstrumentation::class);

    $this->valuestore = Valuestore::make(__DIR__.'/testJob.json')->flush();
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
        ->getName()->toBe('process default');
});

it('can trace queue jobs', function () {
    withRootSpan(function () {
        dispatch(new TestJob($this->valuestore));
    });

    Artisan::call('queue:work', [
        '--once' => true,
    ]);

    $root = getRecordedSpans()->first(fn (ImmutableSpan $span) => $span->getName() === 'root');
    $enqueueSpan = getRecordedSpans()->first(fn (ImmutableSpan $span) => $span->getName() === 'send default');
    $processSpan = getRecordedSpans()->first(fn (ImmutableSpan $span) => $span->getName() === 'process default');

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
        ->get('logContextInJob')->toMatchArray(['trace_id' => $traceId]);

    expect($enqueueSpan)
        ->toBeInstanceOf(ImmutableSpan::class)
        ->getAttributes()->toMatchArray([
            MessagingIncubatingAttributes::MESSAGING_SYSTEM => 'redis',
            MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE => 'send',
            MessagingIncubatingAttributes::MESSAGING_MESSAGE_ID => $this->valuestore->get('uuid'),
            MessagingIncubatingAttributes::MESSAGING_DESTINATION_NAME => 'default',
            'messaging.message.job_name' => TestJob::class,
        ]);

    expect($processSpan)
        ->toBeInstanceOf(ImmutableSpan::class)
        ->getTraceId()->toBe($enqueueSpan->getTraceId())
        ->getAttributes()->toMatchArray([
            MessagingIncubatingAttributes::MESSAGING_SYSTEM => 'redis',
            MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE => 'process',
            MessagingIncubatingAttributes::MESSAGING_MESSAGE_ID => $this->valuestore->get('uuid'),
            MessagingIncubatingAttributes::MESSAGING_DESTINATION_NAME => 'default',
            'messaging.message.job_name' => TestJob::class,
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
    $sqlSpan = getRecordedSpans()->first(fn (ImmutableSpan $span) => $span->getName() === 'SELECT');
    $enqueueSpan = getRecordedSpans()->first(fn (ImmutableSpan $span) => $span->getName() === 'send default');
    $processSpan = getRecordedSpans()->first(fn (ImmutableSpan $span) => $span->getName() === 'process default');

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
        ->get('logContextInJob')->toMatchArray(['trace_id' => $root->getTraceId()]);
});

it('can trace queue failing jobs', function () {
    withRootSpan(function () {
        dispatch(new TestJob($this->valuestore, fail: true));
    });

    Artisan::call('queue:work', [
        '--once' => true,
        '--tries' => 1,
        '--timeout' => 3,
    ]);

    $root = getRecordedSpans()->first(fn (ImmutableSpan $span) => $span->getName() === 'root');
    $enqueueSpan = getRecordedSpans()->first(fn (ImmutableSpan $span) => $span->getName() === 'send default');
    $processSpan = getRecordedSpans()->first(fn (ImmutableSpan $span) => $span->getName() === 'process default');

    assert($root instanceof ImmutableSpan);
    assert($enqueueSpan instanceof ImmutableSpan);
    assert($processSpan instanceof ImmutableSpan);

    $traceId = $enqueueSpan->getTraceId();
    $spanId = $enqueueSpan->getSpanId();

    expect($this->valuestore)
        ->get('uuid')->not->toBeNull()
        ->get('traceparentInJob')->toBe(sprintf('00-%s-%s-01', $traceId, $spanId))
        ->get('traceIdInJob')->toBe($traceId)
        ->get('logContextInJob')->toMatchArray(['trace_id' => $traceId]);

    expect($enqueueSpan)
        ->getStatus()->getCode()->toBe(StatusCode::STATUS_UNSET)
        ->getAttributes()->toMatchArray([
            MessagingIncubatingAttributes::MESSAGING_SYSTEM => 'redis',
            MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE => 'send',
            MessagingIncubatingAttributes::MESSAGING_MESSAGE_ID => $this->valuestore->get('uuid'),
            MessagingIncubatingAttributes::MESSAGING_DESTINATION_NAME => 'default',
            'messaging.message.job_name' => TestJob::class,
        ]);

    expect($processSpan)
        ->getStatus()->getCode()->toBe(StatusCode::STATUS_ERROR)
        ->getEvents()->toHaveCount(1)
        ->getEvents()->{0}->getName()->toBe('exception')
        ->getAttributes()->toMatchArray([
            MessagingIncubatingAttributes::MESSAGING_SYSTEM => 'redis',
            MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE => 'process',
            MessagingIncubatingAttributes::MESSAGING_MESSAGE_ID => $this->valuestore->get('uuid'),
            MessagingIncubatingAttributes::MESSAGING_DESTINATION_NAME => 'default',
            'messaging.message.job_name' => TestJob::class,
        ]);
});
