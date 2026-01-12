<?php

use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Support\TraceBuffer;
use OpenTelemetry\API\Trace\SpanContextInterface;
use Spatie\TestTime\TestTime;

beforeEach(function () {
    TestTime::freeze('Y-m-d H:i:s', '2022-01-01 00:00:00');
});

test('getTraceId returns the trace id', function () {
    $traceId = 'test-trace-id-123';
    $buffer = new TraceBuffer($traceId);

    expect($buffer->getTraceId())->toBe($traceId);
});

test('getSpans returns empty array when no spans added', function () {
    $buffer = new TraceBuffer('trace-1');

    expect($buffer->getSpans())->toBeArray()->toBeEmpty();
});

test('getSpans returns all added spans', function () {
    $buffer = new TraceBuffer('trace-1');

    $span1 = Tracer::newSpan('span-1')->start();
    assert($span1 instanceof \OpenTelemetry\SDK\Trace\Span);
    $span1->end();

    $span2 = Tracer::newSpan('span-2')->start();
    assert($span2 instanceof \OpenTelemetry\SDK\Trace\Span);
    $span2->end();

    $buffer->addSpan($span1);
    $buffer->addSpan($span2);

    $spans = $buffer->getSpans();

    expect($spans)
        ->toBeArray()
        ->toHaveCount(2)
        ->sequence(
            fn ($span) => $span->toBe($span1),
            fn ($span) => $span->toBe($span2)
        );
});

test('getRootSpan returns null when no spans added', function () {
    $buffer = new TraceBuffer('trace-1');

    expect($buffer->getRootSpan())->toBeNull();
});

test('getRootSpan identifies span without valid parent as root', function () {
    $buffer = new TraceBuffer('trace-1');

    // Create a root span (no parent)
    $rootSpan = Tracer::newSpan('root')->start();
    assert($rootSpan instanceof \OpenTelemetry\SDK\Trace\Span);
    $rootSpan->end();

    $buffer->addSpan($rootSpan);

    expect($buffer->getRootSpan())->toBe($rootSpan);
});

test('getRootSpan identifies first parentless span as root when multiple spans exist', function () {
    $buffer = new TraceBuffer('trace-1');

    // Create root span
    $rootSpan = Tracer::newSpan('root')->start();
    assert($rootSpan instanceof \OpenTelemetry\SDK\Trace\Span);
    $scope = $rootSpan->activate();

    // Create child span
    $childSpan = Tracer::newSpan('child')->start();
    assert($childSpan instanceof \OpenTelemetry\SDK\Trace\Span);
    $childSpan->end();

    $scope->detach();
    $rootSpan->end();

    // Add child first, then root
    $buffer->addSpan($childSpan);
    $buffer->addSpan($rootSpan);

    // Root should be identified correctly
    expect($buffer->getRootSpan())->toBe($rootSpan);
});

test('getDecisionDurationMs returns zero when no spans added', function () {
    $buffer = new TraceBuffer('trace-1');

    expect($buffer->getDecisionDurationMs())->toBe(0);
});

test('getDecisionDurationMs returns elapsed time since first span added', function () {
    $buffer = new TraceBuffer('trace-1');

    $span = Tracer::newSpan('span')->start();
    assert($span instanceof \OpenTelemetry\SDK\Trace\Span);
    $span->end();

    $buffer->addSpan($span);

    // Advance time by 500ms
    TestTime::addMillis(500);

    expect($buffer->getDecisionDurationMs())->toBe(500);

    // Advance time by another 300ms
    TestTime::addMillis(300);

    expect($buffer->getDecisionDurationMs())->toBe(800);
});

test('getTraceDurationMs returns zero when no spans added', function () {
    $buffer = new TraceBuffer('trace-1');

    expect($buffer->getTraceDurationMs())->toBe(0);
});

test('getTraceDurationMs returns duration of single span', function () {
    $buffer = new TraceBuffer('trace-1');

    $span = Tracer::newSpan('span')->start();
    assert($span instanceof \OpenTelemetry\SDK\Trace\Span);

    TestTime::addMillis(250);
    $span->end();

    $buffer->addSpan($span);

    expect($buffer->getTraceDurationMs())->toBe(250);
});

test('getTraceDurationMs returns duration from earliest start to latest end across multiple spans', function () {
    $buffer = new TraceBuffer('trace-1');

    // Create root span
    $rootSpan = Tracer::newSpan('root')->start();
    assert($rootSpan instanceof \OpenTelemetry\SDK\Trace\Span);
    $scope = $rootSpan->activate();

    TestTime::addMillis(100);

    // Create child span
    $childSpan = Tracer::newSpan('child')->start();
    assert($childSpan instanceof \OpenTelemetry\SDK\Trace\Span);

    TestTime::addMillis(200);
    $childSpan->end();

    TestTime::addMillis(100);
    $scope->detach();
    $rootSpan->end();

    $buffer->addSpan($childSpan);
    $buffer->addSpan($rootSpan);

    // Total duration should be 400ms (from root start to root end)
    expect($buffer->getTraceDurationMs())->toBe(400);
});

test('getTraceDurationMs handles overlapping spans correctly', function () {
    $buffer = new TraceBuffer('trace-1');

    // Create parent span
    $parentSpan = Tracer::newSpan('parent')->start();
    assert($parentSpan instanceof \OpenTelemetry\SDK\Trace\Span);
    $scope = $parentSpan->activate();

    TestTime::addMillis(50);

    // Create first child
    $child1 = Tracer::newSpan('child-1')->start();
    assert($child1 instanceof \OpenTelemetry\SDK\Trace\Span);
    TestTime::addMillis(100);
    $child1->end();

    TestTime::addMillis(50);

    // Create second child
    $child2 = Tracer::newSpan('child-2')->start();
    assert($child2 instanceof \OpenTelemetry\SDK\Trace\Span);
    TestTime::addMillis(150);
    $child2->end();

    TestTime::addMillis(50);
    $scope->detach();
    $parentSpan->end();

    $buffer->addSpan($child1);
    $buffer->addSpan($child2);
    $buffer->addSpan($parentSpan);

    // Total duration: 50 + 100 + 50 + 150 + 50 = 400ms
    expect($buffer->getTraceDurationMs())->toBe(400);
});

test('addSpan updates buffer created timestamp on first span', function () {
    $buffer = new TraceBuffer('trace-1');

    // Initially decision duration should be 0
    expect($buffer->getDecisionDurationMs())->toBe(0);

    $span = Tracer::newSpan('span')->start();
    assert($span instanceof \OpenTelemetry\SDK\Trace\Span);
    $span->end();

    $buffer->addSpan($span);

    // After adding first span, decision duration should start tracking
    expect($buffer->getDecisionDurationMs())->toBe(0);

    TestTime::addMillis(100);

    // After time passes, decision duration should reflect elapsed time
    expect($buffer->getDecisionDurationMs())->toBe(100);
});

test('addSpan maintains chronological order when spans end in different order', function () {
    $buffer = new TraceBuffer('trace-1');

    // Create parent
    $parent = Tracer::newSpan('parent')->start();
    assert($parent instanceof \OpenTelemetry\SDK\Trace\Span);
    $scope = $parent->activate();

    TestTime::addMillis(50);

    // Create child
    $child = Tracer::newSpan('child')->start();
    assert($child instanceof \OpenTelemetry\SDK\Trace\Span);

    TestTime::addMillis(100);
    $child->end();

    TestTime::addMillis(50);
    $scope->detach();
    $parent->end();

    // Add in order: child first, parent second
    $buffer->addSpan($child);
    $buffer->addSpan($parent);

    $spans = $buffer->getSpans();
    expect($spans)
        ->toHaveCount(2)
        ->sequence(
            fn ($span) => $span->toBe($child),
            fn ($span) => $span->toBe($parent)
        );

    // Trace duration should still be calculated correctly
    expect($buffer->getTraceDurationMs())->toBe(200);
});
