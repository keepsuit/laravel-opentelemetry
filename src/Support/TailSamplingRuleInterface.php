<?php

namespace Keepsuit\LaravelOpenTelemetry\Support;

interface TailSamplingRuleInterface
{
    /**
     * Initialize the rule with options from config
     */
    public function initialize(array $options): void;

    /**
     * Evaluate the rule against the TraceBuffer.
     * Return a SamplingResult (Keep|Drop|Sample) or null if no decision.
     */
    public function evaluate(TraceBuffer $trace): ?SamplingResult;
}
