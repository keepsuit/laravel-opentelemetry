<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Closure;
use Exception;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
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
    public function build(string $name): SpanBuilderInterface
    {
        return $this->tracer->spanBuilder($name);
    }

    /**
     * @phpstan-param non-empty-string $name
     * @phpstan-param SpanKind::KIND_* $spanKind
     */
    public function start(
        string $name,
        int $spanKind = SpanKind::KIND_INTERNAL,
        ?ContextInterface $context = null
    ): SpanInterface {
        return $this->build($name)
            ->setSpanKind($spanKind)
            ->setParent($context)
            ->startSpan();
    }

    /**
     * @template U
     *
     * @param  non-empty-string  $name
     * @param  Closure(SpanInterface $span): U  $callback
     * @phpstan-param SpanKind::KIND_* $spanKind
     *
     * @throws Exception
     * @return U
     *
     */
    public function measure(string $name, Closure $callback, int $spanKind = SpanKind::KIND_INTERNAL)
    {
        $span = $this->start($name, $spanKind);
        $scope = $span->activate();

        try {
            $result = $callback($span);

            // Fix: Dispatch is effective only on destruct
            if ($result instanceof PendingDispatch) {
                $result = null;
            }

            return $result;
        } catch (Exception $exception) {
            $span->recordException($exception);

            throw $exception;
        } finally {
            $span->end();
            $scope->detach();
        }
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

    protected function setTraceIdForLogs(SpanInterface $span): void
    {
        if (config('opentelemetry.logs.inject_trace_id', true)) {
            $field = config('opentelemetry.logs.trace_id_field', 'traceId');

            Log::shareContext([
                $field => $span->getContext()->getTraceId(),
            ]);
        }
    }
}
