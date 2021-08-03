<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use OpenTelemetry\Sdk\Trace\NoopSpan;
use OpenTelemetry\Sdk\Trace\SpanContext;
use OpenTelemetry\Trace\Span;
use OpenTelemetry\Trace\SpanKind;
use OpenTelemetry\Trace\Tracer as OpenTelemetryTracer;
use Spiral\GRPC\ContextInterface;

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

        $headers['x-b3-traceid'] = [$activeSpan->getContext()->getTraceId()];
        $headers['x-b3-spanid'] = [$activeSpan->getContext()->getSpanId()];
        $headers['x-b3-sampled'] = [$activeSpan->isSampled() ? '1' : '0'];

        if ($activeSpan->getParent()) {
            $headers['x-b3-parentspanid'] = [$activeSpan->getParent()->getSpanId()];
        }

        return $headers;
    }

    public function initFromRequest(Request $request): self
    {
        return $this->initFromB3Headers([
            'x-b3-traceid' => $request->header('x-b3-traceid'),
            'x-b3-spanid' => $request->header('x-b3-spanid'),
            'x-b3-sampled' => $request->header('x-b3-sampled'),
            'x-b3-parentspanid' => $request->header('x-b3-parentspanid'),
        ]);
    }

    public function initFromGrpcContext(ContextInterface $ctx): self
    {
        return $this->initFromB3Headers([
            'x-b3-traceid' => $ctx->getValue('x-b3-traceid'),
            'x-b3-spanid' => $ctx->getValue('x-b3-spanid'),
            'x-b3-sampled' => $ctx->getValue('x-b3-sampled'),
            'x-b3-parentspanid' => $ctx->getValue('x-b3-parentspanid'),
        ]);
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
