<?php

namespace Keepsuit\LaravelOpenTelemetry\Support;

use Closure;
use Illuminate\Foundation\Bus\PendingDispatch;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use Throwable;

class SpanBuilder implements SpanBuilderInterface
{
    public function __construct(
        protected SpanBuilderInterface $spanBuilder
    ) {
    }

    public function setParent($context): SpanBuilder
    {
        $this->spanBuilder->setParent($context);

        return $this;
    }

    public function addLink(SpanContextInterface $context, iterable $attributes = []): SpanBuilder
    {
        $this->spanBuilder->addLink($context, $attributes);

        return $this;
    }

    /**
     * @param  mixed  $value
     */
    public function setAttribute(string $key, $value): SpanBuilder
    {
        $this->spanBuilder->setAttribute($key, $value);

        return $this;
    }

    public function setAttributes(iterable $attributes): SpanBuilder
    {
        $this->spanBuilder->setAttributes($attributes);

        return $this;
    }

    public function setStartTimestamp(int $timestampNanos): SpanBuilder
    {
        $this->spanBuilder->setStartTimestamp($timestampNanos);

        return $this;
    }

    public function setSpanKind(int $spanKind): SpanBuilder
    {
        $this->spanBuilder->setSpanKind($spanKind);

        return $this;
    }

    public function startSpan(): SpanInterface
    {
        return $this->spanBuilder->startSpan();
    }

    /**
     * @template U
     *
     * @param  Closure(SpanInterface $span): U  $callback
     * @return U
     *
     * @throws Throwable
     */
    public function measure(Closure $callback): mixed
    {
        $span = $this->startSpan();
        $scope = $span->activate();

        try {
            $result = $callback($span);

            // Fix: Dispatch is effective only on destruct
            if ($result instanceof PendingDispatch) {
                $result = null;
            }

            return $result;
        } catch (Throwable $exception) {
            $span->recordException($exception);

            throw $exception;
        } finally {
            $span->end();
            $scope->detach();
        }
    }
}
