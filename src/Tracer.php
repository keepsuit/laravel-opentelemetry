<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Closure;
use Exception;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\Span;
use Throwable;

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
    public function start(string $name, int $spanKind = SpanKind::KIND_INTERNAL): SpanInterface
    {
        return $this->build($name)->setSpanKind($spanKind)->startSpan();
    }

    /**
     * @template U
     *
     * @param  non-empty-string  $name
     * @param  Closure(SpanInterface $span): U  $callback
     * @return U
     *
     * @throws Exception
     */
    public function measure(string $name, Closure $callback)
    {
        $span = $this->start($name, SpanKind::KIND_INTERNAL);
        $scope = $span->activate();

        try {
            $result = $callback($span);
        } catch (Exception $exception) {
            $this->recordExceptionToSpan($span, $exception);

            throw $exception;
        } finally {
            $span->end();
            $scope->detach();
        }

        return $result;
    }

    /**
     * @template U
     *
     * @param  non-empty-string  $name
     * @param  Closure(SpanInterface $span): U  $callback
     * @return U
     *
     * @throws Exception
     */
    public function measureAsync(string $name, Closure $callback)
    {
        $span = $this->start($name, SpanKind::KIND_PRODUCER);
        $scope = $span->activate();

        try {
            $result = $callback($span);

            // Fix: Dispatch is effective only on destruct
            if ($result instanceof PendingDispatch) {
                $result = null;
            }
        } catch (Exception $exception) {
            $this->recordExceptionToSpan($span, $exception);

            throw $exception;
        } finally {
            $span->end();
            $scope->detach();
        }

        return $result;
    }

    public function recordExceptionToSpan(SpanInterface $span, Throwable $exception): SpanInterface
    {
        return $span->recordException($exception)
            ->setStatus(StatusCode::STATUS_ERROR);
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

    public function propagationHeaders(ContextInterface $context = null): array
    {
        $headers = [];

        $this->propagator->inject($headers, context: $context);

        return $headers;
    }

    public function extractContextFromPropagationHeaders(array $headers): ContextInterface
    {
        return $this->propagator->extract($headers);
    }

    public function setRootSpan(SpanInterface $span): void
    {
        $this->setTraceIdForLogs($span);
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
