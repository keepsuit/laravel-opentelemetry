<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Closure;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Log;
use Keepsuit\LaravelOpenTelemetry\Support\SpanBuilder;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\Span;

class Tracer
{
    public function __construct(
        protected TracerInterface $tracer,
        protected TextMapPropagatorInterface $propagator
    ) {
    }

    /**
     * @phpstan-param non-empty-string $name
     */
    public function newSpan(string $name): SpanBuilder
    {
        return new SpanBuilder($this->tracer->spanBuilder($name));
    }

    /**
     * @phpstan-param non-empty-string $name
     */
    public function start(string $name): SpanInterface
    {
        return $this->newSpan($name)->startSpan();
    }

    /**
     * @template U
     *
     * @param  non-empty-string  $name
     * @param  Closure(SpanInterface $span): U  $callback
     * @throws \Throwable
     * @return (U is PendingDispatch ? null : U)
     *
     */
    public function measure(string $name, Closure $callback)
    {
        return $this->newSpan($name)->measure($callback);
    }

    public function currentContext(): ContextInterface
    {
        return Context::getCurrent();
    }

    public function activeScope(): ?ScopeInterface
    {
        return Context::storage()->scope();
    }

    public function activeSpan(): SpanInterface
    {
        return Span::getCurrent();
    }

    public function traceId(): string
    {
        return $this->activeSpan()->getContext()->getTraceId();
    }

    public function propagationHeaders(?ContextInterface $context = null): array
    {
        $headers = [];

        $this->propagator->inject($headers, context: $context);

        return $headers;
    }

    public function extractContextFromPropagationHeaders(array $headers): ContextInterface
    {
        return $this->propagator->extract($headers);
    }

    public function isRecording(): bool
    {
        $enabled = config('opentelemetry.enabled', true);

        if (is_bool($enabled)) {
            return $enabled;
        }

        if ($enabled === 'parent') {
            return Span::getCurrent()->getContext()->isSampled();
        }

        return false;
    }

    public function updateLogContext(): void
    {
        if (config('opentelemetry.logs.inject_trace_id', true)) {
            $field = config('opentelemetry.logs.trace_id_field', 'traceId');

            Log::shareContext([
                $field => $this->traceId(),
            ]);
        }
    }
}
