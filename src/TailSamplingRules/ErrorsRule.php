<?php

namespace Keepsuit\LaravelOpenTelemetry\TailSamplingRules;

use Keepsuit\LaravelOpenTelemetry\Support\SamplingResult;
use Keepsuit\LaravelOpenTelemetry\Support\TraceBuffer;
use OpenTelemetry\API\Trace\StatusCode as ApiStatusCode;

final class ErrorsRule implements TailSamplingRuleInterface
{
    public function initialize(array $options): void {}

    public function evaluate(TraceBuffer $trace): SamplingResult
    {
        foreach ($trace->getSpans() as $span) {
            $spanData = $span->toSpanData();

            if ($spanData->getStatus()->getCode() === ApiStatusCode::STATUS_ERROR) {
                return SamplingResult::Keep;
            }
        }

        return SamplingResult::Forward;
    }
}
