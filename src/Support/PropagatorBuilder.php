<?php

namespace Keepsuit\LaravelOpenTelemetry\Support;

use Illuminate\Support\Facades\Log;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Registry;

final class PropagatorBuilder
{
    public static function new(): PropagatorBuilder
    {
        return new PropagatorBuilder();
    }

    public function build(string $propagators): TextMapPropagatorInterface
    {
        $propagators = trim($propagators);

        if ($propagators === '') {
            return NoopTextMapPropagator::getInstance();
        }

        $propagators = explode(',', $propagators);

        if (count($propagators) === 1) {
            return $this->buildPropagator($propagators[0]);
        }

        return new MultiTextMapPropagator(array_map(fn (string $name) => $this->buildPropagator($name), $propagators));
    }

    protected function buildPropagator(string $name): TextMapPropagatorInterface
    {
        try {
            return Registry::textMapPropagator($name);
        } catch (\RuntimeException $e) {
            Log::warning($e->getMessage());
        }

        return NoopTextMapPropagator::getInstance();
    }
}
