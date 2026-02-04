<?php

namespace Keepsuit\LaravelOpenTelemetry\Tests\Support;

use Keepsuit\LaravelOpenTelemetry\TailSampling\SamplingResult;
use Keepsuit\LaravelOpenTelemetry\TailSampling\TailSamplingRuleInterface;
use Keepsuit\LaravelOpenTelemetry\TailSampling\TraceBuffer;

class TestTailSamplingRule implements TailSamplingRuleInterface
{
    public function __construct(protected SamplingResult $result) {}

    public function initialize(array $options): void {}

    public function evaluate(TraceBuffer $trace): SamplingResult
    {
        return $this->result;
    }
}
