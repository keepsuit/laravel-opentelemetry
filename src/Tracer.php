<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Closure;
use Illuminate\Http\Request;
use OpenTelemetry\API\Trace\AbstractSpan;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\Span;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Spiral\RoadRunner\GRPC\ContextInterface;

class Tracer
{
    protected TracerInterface $tracer;

    public function __construct(protected TracerProvider $tracerProvider)
    {
        $this->tracer = $this->tracerProvider->getTracer();
    }

    /**
     * @phpstan-param non-empty-string $name
     * @phpstan-param SpanKind::KIND_* $spanKind
     */
    public function build(string $name, int $spanKind = SpanKind::KIND_INTERNAL): SpanBuilderInterface
    {
        return $this->tracer->spanBuilder($name)->setSpanKind($spanKind);

        //        // Temporary fix until SpanKind is sent correctly by opentelemetry library
        //        if ($spanKind === SpanKind::KIND_CLIENT) {
        //            $span->setAttribute('span.kind', 'client');
        //        }
        //        if ($spanKind === SpanKind::KIND_SERVER) {
        //            $span->setAttribute('span.kind', 'server');
        //        }
    }

    /**
     * @phpstan-param non-empty-string $name
     * @phpstan-param SpanKind::KIND_* $spanKind
     */
    public function start(string $name, int $spanKind = SpanKind::KIND_INTERNAL): SpanInterface
    {
        return $this->build($name, $spanKind)->startSpan();
    }

    /**
     * @phpstan-param non-empty-string $name
     */
    public function measure(string $name, Closure $callback)
    {
        $span = $this->start($name);
        $span->activate();

        try {
            $result = $callback($span);
        } catch (\Exception $exception) {
            throw $exception;
        } finally {
            $span->end();
        }

        return $result;
    }

    public function activeSpan(): SpanInterface
    {
        return Span::fromContext(Context::getCurrent());
    }

    public function activeSpanB3Headers(): array
    {
        $headers = [];

        $activeSpan = $this->activeSpan();

        if (! $activeSpan->getContext()->isValid()) {
            return [];
        }

        $spanContext = $activeSpan->getContext();

        $headers['x-b3-traceid'] = [$spanContext->getTraceId()];
        $headers['x-b3-spanid'] = [$spanContext->getSpanId()];
        $headers['x-b3-sampled'] = [$spanContext->isSampled() ? '1' : '0'];

        if ($activeSpan instanceof Span && $activeSpan->getParentContext()->isValid()) {
            $headers['x-b3-parentspanid'] = [$activeSpan->getParentContext()->getSpanId()];
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
        $sampled = $headers->get('x-b3-sampled', '1') === '1';

        if ($traceId == null || $spanId == null) {
            return $this;
        }

        $spanContext = SpanContext::createFromRemoteParent(
            traceId: $traceId,
            spanId: $spanId,
            traceFlags: $sampled ? SpanContextInterface::TRACE_FLAG_SAMPLED : SpanContextInterface::TRACE_FLAG_DEFAULT
        );

        if ($spanContext->isValid()) {
            Context::getCurrent()->withContextValue(AbstractSpan::wrap($spanContext));
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

        if ($enabled === 'parent') {
            return AbstractSpan::fromContext(Context::getCurrent())->getContext()->isSampled();
        }

        return false;
    }

    /**
     * @phpstan-param  non-empty-string $grpcFullName Format <package>.<serviceName>/<methodName>
     */
    public function startGrpcClientTracing(string $grpcFullName): SpanInterface
    {
        [$serviceName, $methodName] = explode('/', $grpcFullName, 2);

        return $this->build($grpcFullName, SpanKind::KIND_CLIENT)
            ->setAttribute('rpc.system', 'grpc')
            ->setAttribute('rpc.service', $serviceName)
            ->setAttribute('rpc.method', $methodName)
            ->setAttribute('grpc.service', $serviceName)
            ->setAttribute('grpc.method', $methodName)
            ->startSpan();
    }
}
