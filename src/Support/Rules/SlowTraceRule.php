<?php

namespace Keepsuit\LaravelOpenTelemetry\Support\Rules;

use Keepsuit\LaravelOpenTelemetry\Support\SamplingResult;
use Keepsuit\LaravelOpenTelemetry\Support\TailSamplingRuleInterface;
use Keepsuit\LaravelOpenTelemetry\Support\TraceBuffer;

final class SlowTraceRule implements TailSamplingRuleInterface
{
    private int $thresholdMs = 2000;

    public function initialize(array $options): void
    {
        $this->thresholdMs = isset($options['threshold_ms']) ? (int) $options['threshold_ms'] : 2000;
    }

    public function evaluate(TraceBuffer $trace): ?SamplingResult
    {
        return $trace->getDurationMs() >= $this->thresholdMs ? SamplingResult::Keep : null;
    }
}
