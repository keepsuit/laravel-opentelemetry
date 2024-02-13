<?php

use Illuminate\Support\Facades\Artisan;
use Keepsuit\LaravelOpenTelemetry\Tests\Support\TestJob;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SemConv\TraceAttributes;
use Spatie\Valuestore\Valuestore;

beforeEach(function () {
    $this->valuestore = Valuestore::make(__DIR__.'/testJob.json')->flush();
});

afterEach(function () {
    $this->valuestore->flush();
});

it('can trace queue jobs', function () {
    withRootSpan(function () {
        dispatch(new TestJob($this->valuestore));
    });

    $parentSpan = collect(getRecordedSpans())
        ->first(fn (ImmutableSpan $span) => $span->getName() === TestJob::class.' enqueue');

    expect($parentSpan)
        ->not->toBeNull();

    assert($parentSpan instanceof ImmutableSpan);

    $traceId = $parentSpan->getTraceId();
    $spanId = $parentSpan->getSpanId();

    expect($traceId)
        ->not->toBeEmpty()
        ->not->toBe('00000000000000000000000000000000');

    expect($spanId)
        ->not->toBeEmpty()
        ->not->toBe('0000000000000000');

    Artisan::call('queue:work', [
        '--once' => true,
    ]);

    expect($this->valuestore)
        ->get('uuid')->not->toBeNull()
        ->get('traceparentInJob')->toBe(sprintf('00-%s-%s-01', $traceId, $spanId))
        ->get('traceIdInJob')->toBe($traceId)
        ->get('logContextInJob')->toMatchArray(['traceId' => $traceId]);

    $jobSpan = collect(getRecordedSpans())
        ->first(fn (ImmutableSpan $span) => $span->getName() === TestJob::class.' process');

    expect($parentSpan)
        ->getAttributes()->toMatchArray([
            TraceAttributes::MESSAGING_SYSTEM => 'redis',
            TraceAttributes::MESSAGING_OPERATION => 'enqueue',
            TraceAttributes::MESSAGE_ID => $this->valuestore->get('uuid'),
            TraceAttributes::MESSAGING_DESTINATION_NAME => 'default',
            TraceAttributes::MESSAGING_DESTINATION_TEMPLATE => TestJob::class,
        ]);

    expect($jobSpan)
        ->not->toBeNull()
        ->getAttributes()->toMatchArray([
            TraceAttributes::MESSAGING_SYSTEM => 'redis',
            TraceAttributes::MESSAGING_OPERATION => 'process',
            TraceAttributes::MESSAGE_ID => $this->valuestore->get('uuid'),
            TraceAttributes::MESSAGING_DESTINATION_NAME => 'default',
            TraceAttributes::MESSAGING_DESTINATION_TEMPLATE => TestJob::class,
        ]);
});
