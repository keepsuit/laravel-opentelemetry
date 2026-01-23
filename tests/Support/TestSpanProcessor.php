<?php

namespace Keepsuit\LaravelOpenTelemetry\Tests\Support;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;

class TestSpanProcessor implements SpanProcessorInterface
{
    public array $ended = [];

    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void {}

    public function onEnd(ReadableSpanInterface $span): void
    {
        $this->ended[] = $span;
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }
}
