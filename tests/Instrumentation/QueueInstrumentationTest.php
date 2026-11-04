<?php

use Illuminate\Support\Facades\DB;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\QueryInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\QueueInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Tests\Support\RetryingTestJob;
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

    executeQueueWork();

    $root = getRecordedSpans()->last();

    expect($root)
        ->toBeInstanceOf(ImmutableSpan::class)
        ->getName()->toBe('process default');
});

it('can trace queue jobs', function () {
    withRootSpan(function () {
        dispatch(new TestJob($this->valuestore));
    });

    executeQueueWork();

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
            MessagingIncubatingAttributes::MESSAGING_SYSTEM_NAME => 'redis',
            MessagingIncubatingAttributes::MESSAGING_OPERATION_NAME => 'send',
            MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE => 'send',
            MessagingIncubatingAttributes::MESSAGING_MESSAGE_ID => $this->valuestore->get('uuid'),
            MessagingIncubatingAttributes::MESSAGING_DESTINATION_NAME => 'default',
            'messaging.message.job_name' => TestJob::class,
        ]);

    expect($processSpan)
        ->toBeInstanceOf(ImmutableSpan::class)
        ->getTraceId()->toBe($enqueueSpan->getTraceId())
        ->getAttributes()->toMatchArray([
            MessagingIncubatingAttributes::MESSAGING_SYSTEM_NAME => 'redis',
            MessagingIncubatingAttributes::MESSAGING_OPERATION_NAME => 'process',
            MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE => 'process',
            MessagingIncubatingAttributes::MESSAGING_MESSAGE_ID => $this->valuestore->get('uuid'),
            MessagingIncubatingAttributes::MESSAGING_DESTINATION_NAME => 'default',
            'messaging.message.job_name' => TestJob::class,
        ]);
});

it('can trace sync queue jobs', function () {
    config()->set('queue.default', 'sync');

    withRootSpan(function () {
        dispatch(new TestJob($this->valuestore));
    });

    $root = getRecordedSpans()->first(fn (ImmutableSpan $span) => $span->getName() === 'root');
    $enqueueSpan = getRecordedSpans()->first(fn (ImmutableSpan $span) => $span->getName() === 'send default');
    $processSpan = getRecordedSpans()->first(fn (ImmutableSpan $span) => $span->getAttributes()->get(MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE) === 'process');

    assert($root instanceof ImmutableSpan);
    assert($enqueueSpan instanceof ImmutableSpan);
    assert($processSpan instanceof ImmutableSpan);

    expect($enqueueSpan)
        ->getParentSpanId()->toBe($root->getSpanId())
        ->getAttributes()->toMatchArray([
            MessagingIncubatingAttributes::MESSAGING_SYSTEM_NAME => 'sync',
            MessagingIncubatingAttributes::MESSAGING_OPERATION_NAME => 'send',
            MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE => 'send',
            MessagingIncubatingAttributes::MESSAGING_MESSAGE_ID => $this->valuestore->get('uuid'),
            MessagingIncubatingAttributes::MESSAGING_DESTINATION_NAME => 'default',
            'messaging.message.job_name' => TestJob::class,
        ]);

    expect($processSpan)
        ->getTraceId()->toBe($enqueueSpan->getTraceId())
        ->getParentSpanId()->toBe($enqueueSpan->getSpanId())
        ->getAttributes()->toMatchArray([
            MessagingIncubatingAttributes::MESSAGING_SYSTEM_NAME => 'sync',
            MessagingIncubatingAttributes::MESSAGING_OPERATION_NAME => 'process',
            MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE => 'process',
            MessagingIncubatingAttributes::MESSAGING_MESSAGE_ID => $this->valuestore->get('uuid'),
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

    executeQueueWork();

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

    executeQueueWork();

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
            MessagingIncubatingAttributes::MESSAGING_SYSTEM_NAME => 'redis',
            MessagingIncubatingAttributes::MESSAGING_OPERATION_NAME => 'send',
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
            MessagingIncubatingAttributes::MESSAGING_SYSTEM_NAME => 'redis',
            MessagingIncubatingAttributes::MESSAGING_OPERATION_NAME => 'process',
            MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE => 'process',
            MessagingIncubatingAttributes::MESSAGING_MESSAGE_ID => $this->valuestore->get('uuid'),
            MessagingIncubatingAttributes::MESSAGING_DESTINATION_NAME => 'default',
            'messaging.message.job_name' => TestJob::class,
        ]);
});

it('does not leak the active consumer span between retry attempts and later jobs', function () {
    $retryingJobStore = Valuestore::make(__DIR__.'/retryingTestJob.json')->flush();
    $followingJobStore = Valuestore::make(__DIR__.'/followingTestJob.json')->flush();

    // First attempt fails, the second succeed
    dispatch(new RetryingTestJob($retryingJobStore));

    executeQueueWork();

    dispatch(new TestJob($followingJobStore));

    executeQueueWork();
    executeQueueWork();

    $retryTraceIds = $retryingJobStore->get('traceIdInJobAttempts');
    $retryUuid = $retryingJobStore->get('uuid');
    $followingUuid = $followingJobStore->get('uuid');

    $processSpans = getRecordedSpans()
        ->filter(fn (ImmutableSpan $span) => $span->getName() === 'process default')
        ->values();

    $retryProcessSpans = $processSpans
        ->filter(fn (ImmutableSpan $span) => $span->getAttributes()->get(MessagingIncubatingAttributes::MESSAGING_MESSAGE_ID) === $retryUuid)
        ->values();

    $followingProcessSpan = $processSpans
        ->first(fn (ImmutableSpan $span) => $span->getAttributes()->get(MessagingIncubatingAttributes::MESSAGING_MESSAGE_ID) === $followingUuid);

    expect($retryTraceIds)
        ->toHaveCount(2)
        ->and($retryTraceIds[0])->not->toBeNull()
        ->and($retryTraceIds[1])->not->toBeNull()
        ->and($retryTraceIds[1])->not->toBe($retryTraceIds[0]);

    expect($retryingJobStore->get('traceparentInJobAttempts'))
        ->toBe([null, null]);

    expect($retryProcessSpans)
        ->toHaveCount(2)
        ->and($retryProcessSpans[0]->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($retryProcessSpans[0]->getEvents())->toHaveCount(1)
        ->and($retryProcessSpans[1]->getStatus()->getCode())->toBe(StatusCode::STATUS_UNSET);

    expect($followingProcessSpan)
        ->not->toBeNull()
        ->getTraceId()->not->toBe($retryTraceIds[1]);

    $retryingJobStore->flush();
    $followingJobStore->flush();
});
