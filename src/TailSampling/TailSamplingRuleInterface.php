<?php

namespace Keepsuit\LaravelOpenTelemetry\TailSampling;

interface TailSamplingRuleInterface
{
    /**
     * Initialize the rule with options from config
     */
    public function initialize(array $options): void;

    /**
     * Evaluate the rule against the TraceBuffer.
     * It should return SamplingResult::Forward if no decision is made.
     */
    public function evaluate(TraceBuffer $trace): SamplingResult;
}
