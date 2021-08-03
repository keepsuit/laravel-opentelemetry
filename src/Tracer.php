<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Closure;
use Illuminate\Support\Arr;
use OpenTelemetry\Sdk\Trace\NoopSpan;
use OpenTelemetry\Sdk\Trace\SpanContext;
use OpenTelemetry\Trace\Span;
use OpenTelemetry\Trace\SpanKind;
use OpenTelemetry\Trace\Tracer as OpenTelemetryTracer;

class Tracer
{
    /** @var Span[] */
    protected array $startedSpans = [];

    protected ?SpanContext $rootParentContext = null;

    public function __construct(protected OpenTelemetryTracer $tracer)
    {
    }

    public function start(string $name, ?Closure $onStart = null): self
    {
        if ($this->rootParentContext !== null && $this->activeSpan() instanceof NoopSpan) {
            $span = $this->tracer->startAndActivateSpanFromContext($name, $this->rootParentContext, isRemote: true, spanKind: SpanKind::KIND_SERVER);
        } else {
            $span = $this->tracer->startAndActivateSpan($name);
        }

        $this->startedSpans[$name] = $span;

        if ($onStart) {
            $onStart($span);
        }

        return $this;
    }

    public function stop(string $name, ?Closure $onStop = null): self
    {
        if (Arr::has($this->startedSpans, $name)) {
            $span = $this->startedSpans[$name];

            if ($onStop) {
                $onStop($span);
            }

            $span->end();

            unset($this->startedSpans[$name]);
        }

        return $this;
    }

    public function measure(string $name, Closure $callback)
    {
        $span = $this->start($name);

        try {
            $result = $callback($span);
        } catch (\Exception $exception) {
            $this->stop($name);

            throw $exception;
        }

        $this->stop($name);

        return $result;
    }

    public function activeSpan(): Span
    {
        return $this->tracer->getActiveSpan();
    }

    public function activeSpanB3Headers(): array
    {
        $headers = [];

        $activeSpan = $this->activeSpan();

        $headers['X-B3-TraceId'] = [$activeSpan->getContext()->getTraceId()];
        $headers['X-B3-SpanId'] = [$activeSpan->getContext()->getSpanId()];
        $headers['X-B3-Sampled'] = [$activeSpan->isSampled() ? '1' : '0'];

        if ($activeSpan->getParent()) {
            $headers['X-B3-ParentSpanId'] = [$activeSpan->getParent()->getSpanId()];
        }

        return $headers;
    }

    public function initFromB3Headers(array $headers): self
    {
        $headers = collect($headers)
            ->mapWithKeys(fn ($value, $key) => [strtolower($key) => is_array($value) ? $value[0] : $value]);

        $traceId = $headers->get('x-b3-traceid');
        $spanId = $headers->get('x-b3-spanid');

        try {
            $this->rootParentContext = new SpanContext(
                traceId: $traceId,
                spanId: $spanId,
                traceFlags: $headers->get('x-b3-sampled', '1') === '1' ? 1 : 0
            );
        } catch (\Exception $exception) {
            $this->rootParentContext = null;
        }

        return $this;
    }
}
