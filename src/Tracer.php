<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Illuminate\Support\Facades\Log;
use Keepsuit\LaravelOpenTelemetry\Support\SpanBuilder;
use OpenTelemetry\API\Trace\SpanContextValidator;
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

    public function traceId(): ?string
    {
        $traceId = $this->activeSpan()->getContext()->getTraceId();

        return SpanContextValidator::isValidTraceId($traceId) ? $traceId : null;
    }

    /**
     * @phpstan-param non-empty-string $name
     */
    public function newSpan(string $name): SpanBuilder
    {
        return new SpanBuilder($this->tracer->spanBuilder($name));
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

    public function updateLogContext(): void
    {
        if (! config('opentelemetry.logs.inject_trace_id', true)) {
            return;
        }

        $traceId = $this->traceId();

        if ($traceId === null) {
            return;
        }

        $field = config('opentelemetry.logs.trace_id_field', 'traceid');

        Log::shareContext([
            $field => $traceId,
        ]);
    }
}
