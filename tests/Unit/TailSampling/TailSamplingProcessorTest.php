<?php

use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\TailSampling\Rules\ErrorsRule;
use Keepsuit\LaravelOpenTelemetry\TailSampling\Rules\SlowTraceRule;
use Keepsuit\LaravelOpenTelemetry\TailSampling\SamplingResult;
use Keepsuit\LaravelOpenTelemetry\TailSampling\TailSamplingProcessor;
use Keepsuit\LaravelOpenTelemetry\TailSampling\TraceBuffer;
use Keepsuit\LaravelOpenTelemetry\Tests\Support\TestSpanProcessor;
use Keepsuit\LaravelOpenTelemetry\Tests\Support\TestTailSamplingRule;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Span;
use Spatie\TestTime\TestTime;

beforeEach(function () {
    TestTime::freeze('Y-m-d H:i:s', '2022-01-01 00:00:00');
});

test('errors rules keep traces with errors', function () {
    $buffer = new TraceBuffer('trace-1');

    $rule = new ErrorsRule;
    $rule->initialize([]);

    $span = Tracer::newSpan('root')->start();
    assert($span instanceof Span);
    $span->setStatus(StatusCode::STATUS_ERROR);
    $span->end();
    $buffer->addSpan($span);
    expect($rule->evaluate($buffer))->toBe(SamplingResult::Keep);
});

test('errors rules forwards traces without errors', function () {
    $buffer = new TraceBuffer('trace-1');

    $rule = new ErrorsRule;
    $rule->initialize([]);

    $span = Tracer::newSpan('root')->start();
    assert($span instanceof Span);
    $span->end();
    $buffer->addSpan($span);
    expect($rule->evaluate($buffer))->toBe(SamplingResult::Forward);
});

test('slow trace rule keeps traces exceeding threshold duration', function () {
    $buffer = new TraceBuffer('trace-2');
    $rule = new SlowTraceRule;
    $rule->initialize(['threshold_ms' => 10]);

    $span = Tracer::newSpan('root')->start();
    assert($span instanceof Span);
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
    assert($span instanceof Span);
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
    assert($root instanceof Span);
    $scope = $root->activate();

    $child = Tracer::newSpan('child')->start();
    assert($child instanceof Span);

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
    assert($parent instanceof Span);
    $scope = $parent->activate();

    $child = Tracer::newSpan('child-opportunistic')->start();
    assert($child instanceof Span);
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

it('evaluates opportunistically when evaluation window is exceeded and rules returns Forward', function () {
    $downstream = new TestSpanProcessor;
    $rule = new TestTailSamplingRule(SamplingResult::Forward);

    // set evaluation window to 0 to force opportunistic evaluation
    $processor = new TailSamplingProcessor($downstream, new AlwaysOnSampler, [$rule], decisionWait: 0);

    // create an active parent so the span we end is not considered the root
    $parent = Tracer::newSpan('parent')->start();
    assert($parent instanceof Span);
    $scope = $parent->activate();

    $child = Tracer::newSpan('child-opportunistic')->start();
    assert($child instanceof Span);
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

it('uses fallback sampler when all rules return Forward and sampler returns RECORD_AND_SAMPLE', function () {
    $downstream = new TestSpanProcessor;
    $rule = new TestTailSamplingRule(SamplingResult::Forward);

    // Use AlwaysOnSampler which returns RECORD_AND_SAMPLE
    $processor = new TailSamplingProcessor($downstream, new AlwaysOnSampler, [$rule], decisionWait: 5000);

    $root = Tracer::newSpan('root')->start();
    assert($root instanceof Span);
    $scope = $root->activate();

    $child = Tracer::newSpan('child')->start();
    assert($child instanceof Span);
    $child->end();

    $scope->detach();
    $root->end();

    // Process spans
    $processor->onEnd($child);
    $processor->onEnd($root);

    // Trace should be kept because AlwaysOnSampler returns RECORD_AND_SAMPLE
    expect($downstream->ended)
        ->toHaveCount(2)
        ->{0}->toBe($child)
        ->{1}->toBe($root);
});

it('uses fallback sampler when all rules return Forward and sampler returns DROP', function () {
    $downstream = new TestSpanProcessor;
    $rule = new TestTailSamplingRule(SamplingResult::Forward);

    // Use AlwaysOffSampler which returns DROP
    $processor = new TailSamplingProcessor($downstream, new AlwaysOffSampler, [$rule], decisionWait: 5000);

    $root = Tracer::newSpan('root')->start();
    assert($root instanceof Span);
    $scope = $root->activate();

    $child = Tracer::newSpan('child')->start();
    assert($child instanceof Span);
    $child->end();

    $scope->detach();
    $root->end();

    // Process spans
    $processor->onEnd($child);
    $processor->onEnd($root);

    // Trace should be dropped because AlwaysOffSampler returns DROP
    expect($downstream->ended)->toBeEmpty();
});

it('uses fallback sampler with multiple Forward rules', function () {
    $downstream = new TestSpanProcessor;
    $rule1 = new TestTailSamplingRule(SamplingResult::Forward);
    $rule2 = new TestTailSamplingRule(SamplingResult::Forward);
    $rule3 = new TestTailSamplingRule(SamplingResult::Forward);

    // Use AlwaysOnSampler - should keep trace when all rules forward
    $processor = new TailSamplingProcessor($downstream, new AlwaysOnSampler, [$rule1, $rule2, $rule3], decisionWait: 5000);

    $root = Tracer::newSpan('root')->start();
    assert($root instanceof Span);
    $root->end();

    $processor->onEnd($root);

    // Trace should be kept because all rules forwarded and sampler says RECORD_AND_SAMPLE
    expect($downstream->ended)
        ->toHaveCount(1)
        ->{0}->toBe($root);
});

it('does not forward buffered spans when a rule returns Drop', function () {
    $downstream = new TestSpanProcessor;
    $rule = new TestTailSamplingRule(SamplingResult::Drop);

    $processor = new TailSamplingProcessor($downstream, new AlwaysOffSampler, [$rule], decisionWait: 5000);

    // create parent and child spans
    $root = Tracer::newSpan('root')->start();
    assert($root instanceof Span);
    $scope = $root->activate();

    $child = Tracer::newSpan('child')->start();
    assert($child instanceof Span);

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

it('does not evaluate a locally rooted trace before its root span ends', function () {
    $downstream = new TestSpanProcessor;
    $rule = new TestTailSamplingRule(SamplingResult::Keep);

    $processor = new TailSamplingProcessor($downstream, new AlwaysOffSampler, [$rule], decisionWait: 5000);

    $root = Tracer::newSpan('root')->start();
    assert($root instanceof Span);
    $scope = $root->activate();

    $child = Tracer::newSpan('child')->start();
    assert($child instanceof Span);
    $child->end();
    $processor->onEnd($child);

    $scope->detach();

    expect($downstream->ended)->toBeEmpty();

    $root->end();
    $processor->onEnd($root);

    expect($downstream->ended)
        ->toHaveCount(2)
        ->{0}->toBe($child)
        ->{1}->toBe($root);
});

it('evaluates the trace when a span with a remote parent ends', function () {
    $downstream = new TestSpanProcessor;
    $rule = new TestTailSamplingRule(SamplingResult::Keep);

    $processor = new TailSamplingProcessor($downstream, new AlwaysOffSampler, [$rule], decisionWait: 5000);

    $remoteContext = Tracer::extractContextFromPropagationHeaders([
        'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
    ]);

    $server = Tracer::newSpan('GET /users')
        ->setSpanKind(SpanKind::KIND_SERVER)
        ->setParent($remoteContext)
        ->start();
    assert($server instanceof Span);
    $scope = $server->activate();

    $query = Tracer::newSpan('SELECT users')->start();
    assert($query instanceof Span);
    TestTime::addMillis(100);
    $query->end();
    $processor->onEnd($query);

    $scope->detach();

    expect($downstream->ended)->toBeEmpty();

    TestTime::addMillis(100);
    $server->end();
    $processor->onEnd($server);

    expect($downstream->ended)
        ->toHaveCount(2)
        ->{0}->toBe($query)
        ->{1}->toBe($server);

    // The trace buffer has been released, a later span of the same trace is evaluated on its own
    $next = Tracer::newSpan('GET /users')
        ->setSpanKind(SpanKind::KIND_SERVER)
        ->setParent($remoteContext)
        ->start();
    assert($next instanceof Span);
    $next->end();
    $processor->onEnd($next);

    expect($downstream->ended)
        ->toHaveCount(3)
        ->{2}->toBe($next);
});

it('does not evaluate the trace when a span with a remote parent already in the buffer ends', function () {
    $downstream = new TestSpanProcessor;
    $rule = new TestTailSamplingRule(SamplingResult::Keep);

    $processor = new TailSamplingProcessor($downstream, new AlwaysOffSampler, [$rule], decisionWait: 5000);

    $server = Tracer::newSpan('GET /users')
        ->setSpanKind(SpanKind::KIND_SERVER)
        ->setParent(Tracer::extractContextFromPropagationHeaders([
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        ]))
        ->start();
    assert($server instanceof Span);
    $scope = $server->activate();

    // A job dispatched and processed in the same process (e.g. sync queue driver):
    // the producer span ends before the consumer span starts from the propagated context.
    $producer = Tracer::newSpan('send default')
        ->setSpanKind(SpanKind::KIND_PRODUCER)
        ->start();
    assert($producer instanceof Span);
    $producer->end();
    $processor->onEnd($producer);

    $consumer = Tracer::newSpan('process default')
        ->setSpanKind(SpanKind::KIND_CONSUMER)
        ->setParent(Tracer::extractContextFromPropagationHeaders([
            'traceparent' => sprintf('00-%s-%s-01', $producer->getContext()->getTraceId(), $producer->getContext()->getSpanId()),
        ]))
        ->start();
    assert($consumer instanceof Span);
    $consumer->end();
    $processor->onEnd($consumer);

    $scope->detach();

    expect($consumer->getParentContext()->isRemote())->toBeTrue();
    expect($downstream->ended)->toBeEmpty();

    $server->end();
    $processor->onEnd($server);

    expect($downstream->ended)
        ->toHaveCount(3)
        ->{0}->toBe($producer)
        ->{1}->toBe($consumer)
        ->{2}->toBe($server);
});
