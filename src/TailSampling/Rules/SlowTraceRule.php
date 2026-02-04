<?php

namespace Keepsuit\LaravelOpenTelemetry\TailSampling\Rules;

use Keepsuit\LaravelOpenTelemetry\TailSampling\SamplingResult;
use Keepsuit\LaravelOpenTelemetry\TailSampling\TailSamplingRuleInterface;
use Keepsuit\LaravelOpenTelemetry\TailSampling\TraceBuffer;

final class SlowTraceRule implements TailSamplingRuleInterface
{
    private int $thresholdMs = 2000;

    public function initialize(array $options): void
    {
        $this->thresholdMs = $options['threshold_ms'] ?? 2000;
    }

    public function evaluate(TraceBuffer $trace): SamplingResult
    {
        if ($trace->getTraceDurationMs() >= $this->thresholdMs) {
            return SamplingResult::Keep;
        }

        return SamplingResult::Forward;
    }
}
