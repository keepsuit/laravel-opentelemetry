<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Keepsuit\LaravelOpenTelemetry\Tests\Support\TestJob;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use Spatie\Valuestore\Valuestore;

beforeEach(function () {
    $this->valuestore = Valuestore::make(__DIR__.'/testJob.json')->flush();
});

afterEach(function () {
    $this->valuestore->flush();
});

it('can trace queue jobs', function () {
    skipTestIfOtelExtensionNotLoaded();

    dispatch(new TestJob($this->valuestore));

    $publishSpan = Arr::first(
        getRecordedSpans(),
        fn (ImmutableSpan $span) => $span->getName() === sprintf('%s publish', TestJob::class)
    );
    assert($publishSpan instanceof ImmutableSpan);

    expect($publishSpan)
        ->getKind()->toBe(SpanKind::KIND_PRODUCER)
        ->getTraceId()->not->toBe('00000000000000000000000000000000')
        ->getSpanId()->not->toBe('0000000000000000')
        ->getAttributes()->toMatchArray([
            'messaging.system' => 'redis',
            'messaging.operation' => 'publish',
            'messaging.destination.name' => 'default',
            'messaging.destination.template' => TestJob::class,
        ]);

    Artisan::call('queue:work', [
        '--once' => true,
    ]);

    expect($this->valuestore)
        ->get('traceparentInJob')->toBe(sprintf('00-%s-%s-01', $publishSpan->getTraceId(), $publishSpan->getSpanId()))
        ->get('traceIdInJob')->toBe($publishSpan->getTraceId());

    $processSpan = Arr::first(
        getRecordedSpans(),
        fn (ImmutableSpan $span) => $span->getName() === sprintf('%s process', TestJob::class)
    );
    assert($processSpan instanceof ImmutableSpan);

    expect($processSpan)
        ->getTraceId()->toBe($publishSpan->getTraceId())
        ->getKind()->toBe(SpanKind::KIND_CONSUMER)
        ->getAttributes()->toMatchArray([
            'messaging.system' => 'redis',
            'messaging.operation' => 'process',
            'messaging.destination.kind' => 'queue',
            'messaging.destination.name' => 'default',
            'messaging.destination.template' => TestJob::class,
        ]);
});
