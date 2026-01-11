<?php

namespace Keepsuit\LaravelOpenTelemetry\Tests\Support;

use Keepsuit\LaravelOpenTelemetry\Support\SamplingResult;
use Keepsuit\LaravelOpenTelemetry\Support\TraceBuffer;
use Keepsuit\LaravelOpenTelemetry\TailSamplingRules\TailSamplingRuleInterface;

class TestTailSamplingRule implements TailSamplingRuleInterface
{
    public function __construct(protected SamplingResult $result) {}

    public function initialize(array $options): void {}

    public function evaluate(TraceBuffer $trace): SamplingResult
    {
        return $this->result;
    }
}
