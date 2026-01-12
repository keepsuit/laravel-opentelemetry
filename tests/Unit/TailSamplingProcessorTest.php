<?php

use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Support\SamplingResult;
use Keepsuit\LaravelOpenTelemetry\Support\TailSamplingProcessor;
use Keepsuit\LaravelOpenTelemetry\Support\TraceBuffer;
use Keepsuit\LaravelOpenTelemetry\TailSamplingRules\ErrorsRule;
use Keepsuit\LaravelOpenTelemetry\TailSamplingRules\SlowTraceRule;
use Keepsuit\LaravelOpenTelemetry\Tests\Support\TestSpanProcessor;
use Keepsuit\LaravelOpenTelemetry\Tests\Support\TestTailSamplingRule;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use Spatie\TestTime\TestTime;

beforeEach(function () {
    TestTime::freeze('Y-m-d H:i:s', '2022-01-01 00:00:00');
});

test('errors rules keep traces with errors', function () {
    $buffer = new TraceBuffer('trace-1');

    $rule = new ErrorsRule;
    $rule->initialize([]);

    $span = Tracer::newSpan('root')->start();
    assert($span instanceof \OpenTelemetry\SDK\Trace\Span);
    $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR);
    $span->end();
    $buffer->addSpan($span);
    expect($rule->evaluate($buffer))->toBe(SamplingResult::Keep);
});

test('errors rules forwards traces without errors', function () {
    $buffer = new TraceBuffer('trace-1');

    $rule = new ErrorsRule;
    $rule->initialize([]);

    $span = Tracer::newSpan('root')->start();
    assert($span instanceof \OpenTelemetry\SDK\Trace\Span);
    $span->end();
    $buffer->addSpan($span);
    expect($rule->evaluate($buffer))->toBe(SamplingResult::Forward);
});

test('slow trace rule keeps traces exceeding threshold duration', function () {
    $buffer = new TraceBuffer('trace-2');
    $rule = new SlowTraceRule;
    $rule->initialize(['threshold_ms' => 10]);

    $span = Tracer::newSpan('root')->start();
    assert($span instanceof \OpenTelemetry\SDK\Trace\Span);
    TestTime::addSeconds(3);
    $span->end();
    $buffer->addSpan($span);

    expect($rule->evaluate($buffer))->toBe(SamplingResult::Keep);
});

test('slow trace rule forwards traces under threshold duration', function () {
    $buffer = new TraceBuffer('trace-2');
    $rule = new SlowTraceRule;
    $rule->initialize(['threshold_ms' => 1000]); // 1 second

    $span = Tracer::newSpan('root')->start();
    assert($span instanceof \OpenTelemetry\SDK\Trace\Span);
    TestTime::addMillis(100);
    $span->end();
    $buffer->addSpan($span);

    expect($rule->evaluate($buffer))->toBe(SamplingResult::Forward);
});

it('forwards buffered spans when a rule returns Keep (root ends triggers evaluation)', function () {
    $downstream = new TestSpanProcessor;
    $rule = new TestTailSamplingRule(SamplingResult::Keep);

    $processor = new TailSamplingProcessor($downstream, new AlwaysOffSampler, [$rule], decisionWait: 5000);

    // create parent and child spans using the package tracer helpers
    $root = Tracer::newSpan('root')->start();
    assert($root instanceof \OpenTelemetry\SDK\Trace\Span);
    $scope = $root->activate();

    $child = Tracer::newSpan('child')->start();
    assert($child instanceof \OpenTelemetry\SDK\Trace\Span);

    // advance time and end child first
    TestTime::addSecond();
    $child->end();
    $processor->onEnd($child);

    // end root after
    TestTime::addSecond();
    $scope->detach();
    $root->end();
    $processor->onEnd($root);

    expect($downstream->ended)
        ->toHaveCount(2)
        ->{0}->toBe($child)
        ->{1}->toBe($root);
});

it('evaluates opportunistically when evaluation window is exceeded', function () {
    $downstream = new TestSpanProcessor;
    $rule = new TestTailSamplingRule(SamplingResult::Keep);

    // set evaluation window to 0 to force opportunistic evaluation
    $processor = new TailSamplingProcessor($downstream, new AlwaysOffSampler, [$rule], decisionWait: 0);

    // create an active parent so the span we end is not considered the root
    $parent = Tracer::newSpan('parent')->start();
    assert($parent instanceof \OpenTelemetry\SDK\Trace\Span);
    $scope = $parent->activate();

    $child = Tracer::newSpan('child-opportunistic')->start();
    assert($child instanceof \OpenTelemetry\SDK\Trace\Span);
    TestTime::addSecond();
    $child->end();

    // do not end parent yet; call onEnd for the child only
    $processor->onEnd($child);

    expect($downstream->ended)
        ->toHaveCount(1)
        ->{0}->toBe($child);

    // cleanup
    $scope->detach();
    $parent->end();
});

it('does not forward buffered spans when a rule returns Drop', function () {
    $downstream = new TestSpanProcessor;
    $rule = new TestTailSamplingRule(SamplingResult::Drop);

    $processor = new TailSamplingProcessor($downstream, new AlwaysOffSampler, [$rule], decisionWait: 5000);

    // create parent and child spans
    $root = Tracer::newSpan('root')->start();
    assert($root instanceof \OpenTelemetry\SDK\Trace\Span);
    $scope = $root->activate();

    $child = Tracer::newSpan('child')->start();
    assert($child instanceof \OpenTelemetry\SDK\Trace\Span);

    // advance time and end child first
    TestTime::addSecond();
    $child->end();
    $processor->onEnd($child);

    // end root after (triggers evaluation)
    TestTime::addSecond();
    $scope->detach();
    $root->end();
    $processor->onEnd($root);

    // verify that no spans were forwarded to downstream
    expect($downstream->ended)->toBeEmpty();
});
