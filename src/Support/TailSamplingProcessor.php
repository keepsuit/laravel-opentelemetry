<?php

namespace Keepsuit\LaravelOpenTelemetry\Support;

use Keepsuit\LaravelOpenTelemetry\TailSamplingRules\TailSamplingRuleInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextKeys;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\Span;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;

final class TailSamplingProcessor implements SpanProcessorInterface
{
    /**
     * @var array<string, TraceBuffer>
     */
    protected array $buffers = [];

    public function __construct(
        protected SpanProcessorInterface $downstream,
        protected SamplerInterface $sampler,
        /** @var TailSamplingRuleInterface[] $rules */
        protected array $rules = [],
        protected int $decisionWait = 5000
    ) {}

    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void {}

    public function onEnd(ReadableSpanInterface $span): void
    {
        $traceId = $span->getContext()->getTraceId();

        if (! isset($this->buffers[$traceId])) {
            $this->buffers[$traceId] = new TraceBuffer($traceId);
        }
        $buffer = $this->buffers[$traceId];

        $buffer->addSpan($span);

        // If a root span (span with no valid parent) has been identified, evaluate immediately
        if ($buffer->getRootSpan() !== null) {
            $this->evaluateTrace($buffer);
            unset($this->buffers[$traceId]);

            return;
        }

        // If buffer duration exceeds decision wait, evaluate immediately
        if ($buffer->getDecisionDurationMs() >= $this->decisionWait) {
            $this->evaluateTrace($buffer);
            unset($this->buffers[$traceId]);

            return;
        }
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        foreach ($this->buffers as $traceId => $buffer) {
            $this->evaluateTrace($buffer);
            unset($this->buffers[$traceId]);
        }

        return $this->downstream->forceFlush($cancellation);
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        foreach ($this->buffers as $traceId => $buffer) {
            $this->evaluateTrace($buffer);
            unset($this->buffers[$traceId]);
        }

        return $this->downstream->shutdown($cancellation);
    }

    protected function evaluateTrace(TraceBuffer $buffer): void
    {
        $rulesResult = $this->evaluateRules($buffer);

        // If rules evaluated to Drop, drop the trace
        if ($rulesResult === SamplingResult::Drop) {
            return;
        }

        // If rules evaluated to Keep, forward the trace to downstream
        if ($rulesResult === SamplingResult::Keep) {
            $this->forwardTraceToDownstream($buffer);

            return;
        }

        // Fallback to sampler when rules return Forward
        $rootSpan = $buffer->getRootSpan();

        if ($rootSpan === null) {
            return;
        }

        $rootSpanData = $rootSpan->toSpanData();

        $parentContext = Context::getCurrent()
            ->with(ContextKeys::span(), Span::wrap($rootSpan->getParentContext()));

        $shouldSample = $this->sampler->shouldSample(
            parentContext: $parentContext,
            traceId: $rootSpan->getContext()->getTraceId(),
            spanName: $rootSpan->getName(),
            spanKind: $rootSpan->getKind(),
            attributes: $rootSpanData->getAttributes(),
            links: $rootSpanData->getLinks(),
        );

        if ($shouldSample->getDecision() === \OpenTelemetry\SDK\Trace\SamplingResult::RECORD_AND_SAMPLE) {
            $this->forwardTraceToDownstream($buffer);
        }
    }

    protected function evaluateRules(TraceBuffer $buffer): SamplingResult
    {
        foreach ($this->rules as $rule) {
            try {
                $result = $rule->evaluate($buffer);

                if ($result !== SamplingResult::Forward) {
                    return $result;
                }
            } catch (\Throwable $throwable) {
                report($throwable);
            }
        }

        return SamplingResult::Forward;
    }

    protected function forwardTraceToDownstream(TraceBuffer $buffer): void
    {
        foreach ($buffer->getSpans() as $span) {
            $this->downstream->onEnd($span);
        }
    }
}
