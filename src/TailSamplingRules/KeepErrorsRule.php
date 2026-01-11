<?php

namespace Keepsuit\LaravelOpenTelemetry\TailSamplingRules;

use Keepsuit\LaravelOpenTelemetry\Support\SamplingResult;
use Keepsuit\LaravelOpenTelemetry\Support\TraceBuffer;

final class KeepErrorsRule implements TailSamplingRuleInterface
{
    public function initialize(array $options): void {}

    public function evaluate(TraceBuffer $trace): SamplingResult
    {
        if ($trace->hasError()) {
            return SamplingResult::Keep;
        }

        return SamplingResult::Forward;
    }
}
