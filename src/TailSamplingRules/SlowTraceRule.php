<?php

namespace Keepsuit\LaravelOpenTelemetry\TailSamplingRules;

use Keepsuit\LaravelOpenTelemetry\Support\SamplingResult;
use Keepsuit\LaravelOpenTelemetry\Support\TraceBuffer;
use OpenTelemetry\API\Common\Time\ClockInterface;

final class SlowTraceRule implements TailSamplingRuleInterface
{
    private int $thresholdMs = 2000;

    public function initialize(array $options): void
    {
        $this->thresholdMs = $options['threshold_ms'] ?? 2000;
    }

    public function evaluate(TraceBuffer $trace): SamplingResult
    {
        if ($trace->getRootSpan() === null) {
            return SamplingResult::Forward;
        }

        $durationMs = (int) ($trace->getRootSpan()->getDuration() / ClockInterface::NANOS_PER_MILLISECOND);
        if ($durationMs >= $this->thresholdMs) {
            return SamplingResult::Keep;
        }

        return SamplingResult::Forward;
    }
}
