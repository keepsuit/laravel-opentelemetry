<?php

namespace Keepsuit\LaravelOpenTelemetry\Support;

use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SamplerInterface;

final class SamplerBuilder
{
    public static function new(): SamplerBuilder
    {
        return new SamplerBuilder;
    }

    public function build(string $sampler, bool $parentBased = false, array $args = []): SamplerInterface
    {
        $instance = $this->buildSampler(strtolower(trim($sampler)), $args);

        if ($parentBased) {
            return new ParentBased($instance);
        }

        return $instance;
    }

    protected function buildSampler(string $name, array $args): SamplerInterface
    {
        return match ($name) {
            'always_on' => new AlwaysOnSampler,
            'traceidratio' => new TraceIdRatioBasedSampler($args['ratio'] ?? 0.05),
            default => new AlwaysOffSampler,
        };
    }
}
