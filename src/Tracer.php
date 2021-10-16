<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use OpenTelemetry\Sdk\Trace\NoopSpan;
use OpenTelemetry\Sdk\Trace\SpanContext;
use OpenTelemetry\Trace\Span;
use OpenTelemetry\Trace\SpanKind;
use OpenTelemetry\Trace\SpanStatus;
use OpenTelemetry\Trace\Tracer as OpenTelemetryTracer;
use Spiral\RoadRunner\GRPC\ContextInterface;

class Tracer
{
    /** @var Span[] */
    protected array $startedSpans = [];

    protected ?SpanContext $rootParentContext = null;

    public function __construct(protected OpenTelemetryTracer $tracer)
    {
    }

    public function start(string $name, ?Closure $onStart = null, int $spanKind = SpanKind::KIND_INTERNAL): self
    {
        if (! $this->isRecording()) {
            return $this;
        }

        if ($this->rootParentContext !== null && $this->activeSpan() instanceof NoopSpan) {
            $span = $this->tracer->startActiveSpan($name, $this->rootParentContext, isRemote: true, spanKind: $spanKind);
        } else {
            $span = $this->tracer->startAndActivateSpan($name, spanKind: $spanKind);
        }

        // Temporary fix until SpanKind is sent correctly by opentelemetry library
        if ($spanKind === SpanKind::KIND_CLIENT) {
            $span->setAttribute('span.kind', 'client');
        }
        if ($spanKind === SpanKind::KIND_SERVER) {
            $span->setAttribute('span.kind', 'server');
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

    public function measure(string $name, Closure $callback, ?Closure $onStart = null, ?Closure $onStop = null)
    {
        $this->start($name, $onStart);

        try {
            $result = $callback();
        } catch (\Exception $exception) {
            $this->stop($name, $onStop);

            throw $exception;
        }

        $this->stop($name, $onStop);

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

        if (! $activeSpan instanceof \OpenTelemetry\Sdk\Trace\Span) {
            return [];
        }

        /** @var SpanContext $spanContext */
        $spanContext = $activeSpan->getContext();

        $headers['x-b3-traceid'] = [$spanContext->getTraceId()];
        $headers['x-b3-spanid'] = [$spanContext->getSpanId()];
        $headers['x-b3-sampled'] = [$spanContext->isSampled() ? '1' : '0'];

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

        if ($traceId == null || $spanId == null) {
            $this->rootParentContext = null;

            return $this;
        }

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

    public function isRecording(): bool
    {
        if (config('opentelemetry.exporter') === null) {
            return false;
        }

        $enabled = config('opentelemetry.enabled', true);

        if (is_bool($enabled)) {
            return $enabled;
        }

        if ($enabled === 'parent' && $this->rootParentContext !== null && $this->rootParentContext->isSampled()) {
            return true;
        }

        return false;
    }

    public function updateSpan(string $name, Closure $callback): self
    {
        $span = Arr::get($this->startedSpans, $name);

        if ($span !== null) {
            $callback($span);
        }

        return $this;
    }

    /**
     * @param string $grpcFullName Format <package>.<serviceName>/<methodName>
     */
    public function startGrpcClientTracing(string $grpcFullName)
    {
        return $this->start(
            name: $grpcFullName,
            spanKind: SpanKind::KIND_CLIENT,
            onStart: function (Span $span) use ($grpcFullName) {
                [$serviceName, $methodName] = explode('/', $grpcFullName, 2);

                $span->setAttribute('rpc.system', 'grpc');
                $span->setAttribute('rpc.service', $serviceName);
                $span->setAttribute('rpc.method', $methodName);
                $span->setAttribute('grpc.service', $serviceName);
                $span->setAttribute('grpc.method', $methodName);
            }
        );
    }

    /**
     * @param string $grpcFullName Format <package>.<serviceName>/<methodName>
     */
    public function stopGrpcClientTracing(string $grpcFullName, ?int $status = null)
    {
        return $this->stop(
            name: $grpcFullName,
            onStop: function (Span $span) use ($status) {
                if ($status === null) {
                    return;
                }

                $span->setAttribute('rpc.grpc.status_code', $status);

                if ($status !== 0) {
                    $span->setSpanStatus(SpanStatus::ERROR);
                    $span->setAttribute('error', true);
                }
            }
        );
    }
}
