<?php

namespace Keepsuit\LaravelOpenTelemetry\Support;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;

final class TailSamplingProcessor implements SpanProcessorInterface
{
    private SpanProcessorInterface $downstream;

    /** @var array<string, TraceBuffer> */
    private array $buffers = [];

    /** @var TailSamplingRuleInterface[] */
    private array $rules = [];

    private int $evaluationWindowMs;

    private int $maxBufferedTraces;

    public function __construct(SpanProcessorInterface $downstream, array $ruleInstances = [], array $options = [])
    {
        $this->downstream = $downstream;
        $this->rules = $ruleInstances;
        $this->evaluationWindowMs = $options['evaluation_window_ms'] ?? 5000;
        $this->maxBufferedTraces = $options['max_buffered_traces'] ?? 10000;
    }

    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
        // no-op
    }

    public function onEnd(ReadableSpanInterface $span): void
    {
        $traceId = $span->getContext()->getTraceId();

        if (! isset($this->buffers[$traceId])) {
            $this->buffers[$traceId] = new TraceBuffer($traceId);
        }

        $buffer = $this->buffers[$traceId];
        $buffer->addSpan($span);

        // If this span is the root span (no parent), evaluate immediately
        try {
            $root = $buffer->getRootSpan();
            if ($root !== null) {
                $rootSpanData = $root->toSpanData();
                if ($rootSpanData->getParentSpanId() === '') {
                    $this->evaluateTrace($traceId);

                    return;
                }
            }
        } catch (\Throwable $e) {
            // ignore and continue
        }

        // opportunistic evaluation: if buffer age exceeds evaluation window
        // check last ended time vs now and evaluate if older than the configured window
        try {
            $last = $buffer->getLastEndEpochNanos();
            if ($last !== null) {
                $nowNs = (int) (microtime(true) * 1_000_000_000);
                $ageMs = (int) floor(($nowNs - $last) / 1_000_000);
                if ($ageMs >= $this->evaluationWindowMs) {
                    $this->evaluateTrace($traceId);

                    return;
                }
            }
        } catch (\Throwable $e) {
            // ignore and continue
        }

        // No immediate evaluation; trace remains buffered until root ends or window elapses
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        // evaluate all pending traces
        foreach (array_keys($this->buffers) as $traceId) {
            $this->evaluateTrace($traceId);
        }

        if (method_exists($this->downstream, 'forceFlush')) {
            return $this->downstream->forceFlush($cancellation);
        }

        return true;
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        // evaluate and clear buffers
        foreach (array_keys($this->buffers) as $traceId) {
            $this->evaluateTrace($traceId);
        }

        if (method_exists($this->downstream, 'shutdown')) {
            return $this->downstream->shutdown($cancellation);
        }

        return true;
    }

    private function evaluateTrace(string $traceId): void
    {
        if (! isset($this->buffers[$traceId])) {
            return;
        }

        $buffer = $this->buffers[$traceId];
        $root = $buffer->getRootSpan();

        // Default: consult configured sampler and parent-based behavior
        $samplerConfig = config('opentelemetry.traces.sampler', []);
        $samplerType = $samplerConfig['type'] ?? 'always_on';
        $samplerParent = $samplerConfig['parent'] ?? true;
        $samplerArgs = $samplerConfig['args'] ?? [];

        // Determine parent sampling state
        $parentIsSampling = false;
        if ($root !== null) {
            try {
                $parentCtx = $root->getParentContext();
                if ($parentCtx !== null && method_exists($parentCtx, 'isSampled') && $parentCtx->isSampled()) {
                    $parentIsSampling = true;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Parent-based handling
        if ($samplerParent) {
            if ($parentIsSampling) {
                $this->forwardTrace($buffer);
                unset($this->buffers[$traceId]);

                return;
            }

            // parent not sampling: evaluate rules; if no decision -> Drop
            $decision = $this->evaluateRules($buffer);

            if ($decision === null) {
                // drop
                unset($this->buffers[$traceId]);

                return;
            }

            $this->applyDecision($decision, $buffer, $samplerType, $samplerParent, $samplerArgs);
            unset($this->buffers[$traceId]);

            return;
        }

        // parent-based is disabled: evaluate rules; if no decision -> fallback to sampler
        $decision = $this->evaluateRules($buffer);

        if ($decision === null) {
            // consult configured sampler
            $sampler = SamplerBuilder::new()->build($samplerType, $samplerParent, $samplerArgs);

            if ($root === null) {
                // nothing to forward
                unset($this->buffers[$traceId]);

                return;
            }

            $shouldSample = $this->shouldSamplerSample($sampler, $root);
            if ($shouldSample) {
                $this->forwardTrace($buffer);
            }

            unset($this->buffers[$traceId]);

            return;
        }

        $this->applyDecision($decision, $buffer, $samplerType, $samplerParent, $samplerArgs);
        unset($this->buffers[$traceId]);
    }

    private function evaluateRules(TraceBuffer $buffer): ?SamplingResult
    {
        foreach ($this->rules as $rule) {
            try {
                $res = $rule->evaluate($buffer);
                if ($res !== null) {
                    return $res;
                }
            } catch (\Throwable $e) {
                // swallow rule exceptions and continue
            }
        }

        return null;
    }

    private function applyDecision(?SamplingResult $decision, TraceBuffer $buffer, string $samplerType, bool $samplerParent, array $samplerArgs): void
    {
        if ($decision === null) {
            return;
        }

        match ($decision) {
            SamplingResult::Keep => $this->forwardTrace($buffer),
            SamplingResult::Drop => null,
            SamplingResult::Sample => $this->handleSampleDecision($buffer, $samplerType, $samplerParent, $samplerArgs),
        };
    }

    private function handleSampleDecision(TraceBuffer $buffer, string $samplerType, bool $samplerParent, array $samplerArgs): void
    {
        $sampler = SamplerBuilder::new()->build($samplerType, $samplerParent, $samplerArgs);

        $root = $buffer->getRootSpan();
        if ($root === null) {
            return;
        }

        if ($this->shouldSamplerSample($sampler, $root)) {
            $this->forwardTrace($buffer);
        }
    }

    private function shouldSamplerSample($sampler, ReadableSpanInterface $root): bool
    {
        try {
            if (method_exists($sampler, 'shouldSample')) {
                // Build inputs according to OpenTelemetry SDK sampler signature:
                // shouldSample(ContextInterface $parentContext, string $traceId, string $name, int $spanKind, \OpenTelemetry\SDK\Trace\Attributes $attributes, array $links)
                $parentContext = $root->getParentContext() ?? $root->getContext();

                $traceId = $root->getContext()->getTraceId();
                $name = $root->getName();
                $kind = $root->getKind();

                $spanData = null;
                try {
                    $spanData = $root->toSpanData();
                } catch (\Throwable $e) {
                    $spanData = null;
                }

                $attributes = null;
                $links = [];

                if ($spanData !== null) {
                    try {
                        $attributes = $spanData->getAttributes();
                    } catch (\Throwable $e) {
                        $attributes = null;
                    }

                    try {
                        $links = $spanData->getLinks() ?? [];
                    } catch (\Throwable $e) {
                        $links = [];
                    }
                }

                // Call sampler
                $result = $sampler->shouldSample($parentContext, $traceId, $name, $kind, $attributes, $links);

                // The result may be an object with isSampled() or a boolean; handle variants
                if (is_object($result) && method_exists($result, 'isSampled')) {
                    return (bool) $result->isSampled();
                }

                if (is_bool($result)) {
                    return $result;
                }

                // Fallback: check trace flags
                return $root->getContext()->isSampled();
            }
        } catch (\Throwable $e) {
            // ignore and fallback
        }

        return $root->getContext()->isSampled();
    }

    private function forwardTrace(TraceBuffer $buffer): void
    {
        foreach ($buffer->getSpans() as $span) {
            try {
                $this->downstream->onEnd($span);
            } catch (\Throwable $e) {
                // swallow
            }
        }
    }
}
