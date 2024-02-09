<?php

use Illuminate\Support\Facades\Log;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Trace\Span;
use Spatie\TestTime\TestTime;

beforeEach(function () {
    TestTime::freeze('Y-m-d H:i:s', '2022-01-01 00:00:00');
});

it('can resolve laravel tracer', function () {
    /** @var \Keepsuit\LaravelOpenTelemetry\Tracer $tracer */
    $tracer = app(\Keepsuit\LaravelOpenTelemetry\Tracer::class);

    expect($tracer)
        ->toBeInstanceOf(\Keepsuit\LaravelOpenTelemetry\Tracer::class)
        ->isRecording()->toBeTrue()
        ->traceId()->toBe('00000000000000000000000000000000')
        ->activeSpan()->toBeInstanceOf(\OpenTelemetry\API\Trace\NonRecordingSpan::class);
});

it('can measure a span', function () {
    $span = Tracer::start('test span');

    expect(Tracer::activeSpan())->not->toBe($span);

    assert($span instanceof Span);
    expect($span)
        ->getName()->toBe('test span')
        ->isRecording()->toBeTrue()
        ->hasEnded()->toBeFalse()
        ->getKind()->toBe(SpanKind::KIND_INTERNAL);

    TestTime::addSecond();

    $span->end();

    expect($span)
        ->isRecording()->toBeFalse()
        ->hasEnded()->toBeTrue()
        ->getDuration()->toBe(1_000_000_000);
});

it('can measure sequential spans', function () {
    $startTimestamp = ClockFactory::getDefault()->now();

    $span1 = Tracer::start('test span 1');
    assert($span1 instanceof Span);

    expect(Tracer::activeSpan())->not->toBe($span1);

    TestTime::addSecond();

    $span1->end();

    $span2 = Tracer::start('test span 2');
    assert($span2 instanceof Span);

    expect(Tracer::activeSpan())->not->toBe($span2);

    TestTime::addSeconds(2);

    $span2->end();

    expect($span1)
        ->getName()->toBe('test span 1')
        ->getKind()->toBe(SpanKind::KIND_INTERNAL)
        ->getDuration()->toBe(1_000_000_000)
        ->getStartEpochNanos()->toBe($startTimestamp);

    expect($span2)
        ->getName()->toBe('test span 2')
        ->getKind()->toBe(SpanKind::KIND_INTERNAL)
        ->getDuration()->toBe(2_000_000_000)
        ->getStartEpochNanos()->toBe($startTimestamp + 1_000_000_000);
});

it('can measure parallel spans', function () {
    $startTimestamp = ClockFactory::getDefault()->now();

    $span1 = Tracer::start('test span 1');
    assert($span1 instanceof Span);

    $span2 = Tracer::start('test span 2');
    assert($span2 instanceof Span);

    expect(Tracer::activeSpan())
        ->not->toBe($span1)
        ->not->toBe($span2);

    TestTime::addSecond();

    $span1->end();

    TestTime::addSeconds(2);

    $span2->end();

    expect($span1)
        ->getName()->toBe('test span 1')
        ->getKind()->toBe(SpanKind::KIND_INTERNAL)
        ->getDuration()->toBe(1_000_000_000)
        ->getStartEpochNanos()->toBe($startTimestamp);

    expect($span2)
        ->getName()->toBe('test span 2')
        ->getKind()->toBe(SpanKind::KIND_INTERNAL)
        ->getDuration()->toBe(3_000_000_000)
        ->getStartEpochNanos()->toBe($startTimestamp);
});

it('can measure nested spans', function () {
    $startTimestamp = ClockFactory::getDefault()->now();

    $span1 = Tracer::start('test span 1');
    assert($span1 instanceof Span);
    $scope = $span1->activate();

    expect(Tracer::activeSpan())->toBe($span1);

    TestTime::addSecond();

    $span2 = Tracer::start('test span 2');
    assert($span2 instanceof Span);

    expect(Tracer::activeSpan())->toBe($span1);

    TestTime::addSeconds(2);

    $span2->end();

    TestTime::addSecond();

    $span1->end();
    $scope->detach();

    expect($span1)
        ->getName()->toBe('test span 1')
        ->getKind()->toBe(SpanKind::KIND_INTERNAL)
        ->getDuration()->toBe(4_000_000_000)
        ->getStartEpochNanos()->toBe($startTimestamp);

    expect($span2)
        ->getName()->toBe('test span 2')
        ->getKind()->toBe(SpanKind::KIND_INTERNAL)
        ->getDuration()->toBe(2_000_000_000)
        ->getStartEpochNanos()->toBe($startTimestamp + 1_000_000_000)
        ->getParentContext()->toBe($span1->getContext());
});

it('can measure a callback', function () {
    /** @var Span $span */
    $span = Tracer::measure('test span', function (SpanInterface $span) {
        TestTime::addSecond();

        expect($span)
            ->getName()->toBe('test span')
            ->getKind()->toBe(SpanKind::KIND_INTERNAL);

        expect(Tracer::activeSpan())->toBe($span);

        return $span;
    });

    expect($span)
        ->hasEnded()->toBeTrue()
        ->getDuration()->toBe(1_000_000_000);
});

it('can record exceptions thrown in the callback', function () {
    $callbackSpan = null;

    try {
        Tracer::measure('test span', function (SpanInterface $span) use (&$callbackSpan) {
            $callbackSpan = $span;

            throw new Exception('test exception');
        });
    } catch (Exception) {
    }

    expect($callbackSpan->toSpanData())
        ->hasEnded()->toBeTrue()
        ->getStatus()->getCode()->toBe(StatusCode::STATUS_UNSET)
        ->getEvents()->toHaveCount(1);

    expect($callbackSpan->toSpanData()->getEvents()[0])
        ->getName()->toBe('exception')
        ->getAttributes()->toMatchArray([
            'exception.type' => 'Exception',
            'exception.message' => 'test exception',
        ]);
});

it('provides headers for propagation', function () {
    $span = Tracer::start('test span');
    $scope = $span->activate();

    expect(Tracer::propagationHeaders())
        ->toMatchArray([
            'traceparent' => sprintf('00-%s-%s-01', $span->getContext()->getTraceId(), $span->getContext()->getSpanId()),
        ]);

    $scope->detach();
    $span->end();
});

it('provides traceId and spanId for propagation', function () {
    $span = Tracer::start('test span');
    $scope = $span->activate();

    expect(Tracer::traceId())->toBe($span->getContext()->getTraceId());

    $scope->detach();
    $span->end();
});

it('provides active span', function () {
    $span = Tracer::start('test span');
    $scope = $span->activate();

    expect(Tracer::activeSpan())->toBe($span);

    $scope->detach();
    $span->end();
});

it('set traceId to log context', function () {
    $span = Tracer::start('test span');
    $scope = $span->activate();

    expect(Log::sharedContext())->toBe([]);

    Tracer::updateLogContext();

    expect(Log::sharedContext())
        ->toMatchArray([
            'traceId' => $span->getContext()->getTraceId(),
        ]);

    $scope->detach();
    $span->end();
});
