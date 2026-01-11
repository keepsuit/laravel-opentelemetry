<?php

namespace Keepsuit\LaravelOpenTelemetry\Support\Rules;

use Keepsuit\LaravelOpenTelemetry\Support\SamplingResult;
use Keepsuit\LaravelOpenTelemetry\Support\TailSamplingRuleInterface;
use Keepsuit\LaravelOpenTelemetry\Support\TraceBuffer;

final class KeepErrorsRule implements TailSamplingRuleInterface
{
    public function initialize(array $options): void {}

    public function evaluate(TraceBuffer $trace): SamplingResult
    {
        return $trace->hasError() ? SamplingResult::Keep : SamplingResult::Forward;
    }
}
