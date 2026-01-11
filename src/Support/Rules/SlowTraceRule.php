<?php

namespace Keepsuit\LaravelOpenTelemetry\Support\Rules;

use Keepsuit\LaravelOpenTelemetry\Support\SamplingResult;
use Keepsuit\LaravelOpenTelemetry\Support\TailSamplingRuleInterface;
use Keepsuit\LaravelOpenTelemetry\Support\TraceBuffer;
use OpenTelemetry\API\Common\Time\ClockInterface;

final class SlowTraceRule implements TailSamplingRuleInterface
{
    private int $thresholdMs = 2000;

    public function initialize(array $options): void
    {
        $this->thresholdMs = isset($options['threshold_ms']) ? (int) $options['threshold_ms'] : 2000;
    }

    public function evaluate(TraceBuffer $trace): SamplingResult
    {
        $root = $trace->getRootSpan();

        if ($root === null) {
            return SamplingResult::Forward;
        }

        $durationMs = (int) ($root->getDuration() / ClockInterface::NANOS_PER_MILLISECOND);
        if ($durationMs >= $this->thresholdMs) {
            return SamplingResult::Keep;
        }

        return SamplingResult::Forward;
    }
}
