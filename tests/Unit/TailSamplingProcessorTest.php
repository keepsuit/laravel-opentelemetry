<?php

use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Support\SamplingResult;
use Keepsuit\LaravelOpenTelemetry\Support\TailSamplingProcessor;
use Keepsuit\LaravelOpenTelemetry\Support\TraceBuffer;
use Keepsuit\LaravelOpenTelemetry\TailSamplingRules\KeepErrorsRule;
use Keepsuit\LaravelOpenTelemetry\TailSamplingRules\SlowTraceRule;
use Keepsuit\LaravelOpenTelemetry\Tests\Support\TestSpanProcessor;
use Keepsuit\LaravelOpenTelemetry\Tests\Support\TestTailSamplingRule;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use Spatie\TestTime\TestTime;

beforeEach(function () {
    TestTime::freeze('Y-m-d H:i:s', '2022-01-01 00:00:00');
});

test('keep errors rules detects errors', function () {
    $buffer = new TraceBuffer('trace-1');
    expect($buffer->hasError())->toBeFalse();

    $rule = new KeepErrorsRule;
    $rule->initialize([]);
    expect($rule->evaluate($buffer))->toBe(SamplingResult::Forward);
});

test('slow trace rule', function () {
    $buffer = new TraceBuffer('trace-2');
    $rule = new SlowTraceRule;
    $rule->initialize(['threshold_ms' => 10]);

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

    // end root after
    TestTime::addSecond();
    $scope->detach();
    $root->end();

    $processor->onEnd($child);
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
