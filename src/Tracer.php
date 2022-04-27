<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Keepsuit\LaravelGrpc\GrpcRequest;
use OpenTelemetry\API\Trace\AbstractSpan;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\Span;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Symfony\Component\HttpFoundation\Response;

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
     * @template U
     *
     * @param non-empty-string $name
     * @param Closure(SpanInterface $span): U $callback
     * @throws Exception
     * @return U
     */
    public function measure(string $name, Closure $callback)
    {
        $span = $this->start($name);
        $span->activate();

        try {
            $result = $callback($span);
        } catch (Exception $exception) {
            $this->recordExceptionToSpan($span, $exception);

            throw $exception;
        } finally {
            $span->end();
        }

        return $result;
    }

    public function recordExceptionToSpan(SpanInterface $span, Exception $exception): SpanInterface
    {
        return $span->recordException($exception)
            ->setStatus(StatusCode::STATUS_ERROR)
            ->setAttribute('error', true);
    }

    public function recordHttpResponseToSpan(SpanInterface $span, Response $response): SpanInterface
    {
        $span->setAttribute('http.status_code', $response->getStatusCode())
            ->setAttribute('http.response_content_length', strlen($response->getContent()));

        if ($response->isSuccessful()) {
            $span->setStatus(StatusCode::STATUS_OK);
        }

        if ($response->isServerError() || $response->isClientError()) {
            $span->setStatus(StatusCode::STATUS_ERROR);
            $span->setAttribute('error', true);
        }

        return $span;
    }

    public function recordGrpcExceptionToSpan(SpanInterface $span, GRPCException $exception): SpanInterface
    {
        return $span->recordException($exception)
            ->setAttribute('rpc.grpc.status_code', $exception->getCode())
            ->setStatus(StatusCode::STATUS_ERROR)
            ->setAttribute('error', true);
    }

    public function recordGrpcSuccessResponseToSpan(SpanInterface $span): SpanInterface
    {
        return $span->setAttribute('rpc.grpc.status_code', \Spiral\RoadRunner\GRPC\StatusCode::OK)
            ->setStatus(StatusCode::STATUS_OK);
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

    public function initFromHttpRequest(Request $request): SpanInterface
    {
        $context = $this->extractContextFromHttpRequest($request);

        /** @var non-empty-string $route */
        $route = rescue(fn () => Route::getRoutes()->match($request)->uri(), $request->path(), false);
        $route = str_starts_with($route, '/') ? $route : '/'.$route;

        $builder = $this->build(name: $route, spanKind: SpanKind::KIND_SERVER);

        if ($context !== null) {
            $builder->setParent($context);
        }

        $span = $builder->startSpan();

        $span->activate();

        $span->setAttribute('http.method', $request->method())
            ->setAttribute('http.url', $request->getUri())
            ->setAttribute('http.target', $request->getRequestUri())
            ->setAttribute('http.route', $route)
            ->setAttribute('http.host', $request->getHttpHost())
            ->setAttribute('http.scheme', $request->getScheme())
            ->setAttribute('http.user_agent', $request->userAgent())
            ->setAttribute('http.request_content_length', $request->header('Content-Length'));

        return $span;
    }

    public function extractContextFromHttpRequest(Request $request): ?Context
    {
        return $this->extractContextFromB3Headers([
            'x-b3-traceid' => $request->header('x-b3-traceid'),
            'x-b3-spanid' => $request->header('x-b3-spanid'),
            'x-b3-sampled' => $request->header('x-b3-sampled'),
            'x-b3-parentspanid' => $request->header('x-b3-parentspanid'),
        ]);
    }

    public function initFromGrpcRequest(GrpcRequest $request): SpanInterface
    {
        $context = $this->extractContextFromGrpcRequest($request);

        $traceName = sprintf('%s/%s', $request->getServiceName() ?? 'Unknown', $request->getMethodName());

        $builder = $this->build(name: $traceName, spanKind: SpanKind::KIND_SERVER);

        if ($context != null) {
            $builder->setParent($context);
        }

        $span = $builder->startSpan();

        $span->activate();

        $span->setAttribute('rpc.system', 'grpc')
            ->setAttribute('rpc.service', $request->getServiceName())
            ->setAttribute('rpc.method', $request->getMethodName())
            ->setAttribute('grpc.service', $request->getServiceName())
            ->setAttribute('grpc.method', $request->method->getName())
            ->setAttribute('net.peer.name', $request->context->getValue(':authority'));

        return $span;
    }

    public function extractContextFromGrpcRequest(GrpcRequest $request): ?Context
    {
        return $this->extractContextFromB3Headers([
            'x-b3-traceid' => $request->context->getValue('x-b3-traceid'),
            'x-b3-spanid' => $request->context->getValue('x-b3-spanid'),
            'x-b3-sampled' => $request->context->getValue('x-b3-sampled'),
            'x-b3-parentspanid' => $request->context->getValue('x-b3-parentspanid'),
        ]);
    }

    public function extractContextFromB3Headers(array $headers): ?Context
    {
        $this->startNewContext();

        $headers = collect($headers)
            ->mapWithKeys(fn ($value, $key) => [strtolower($key) => is_array($value) ? $value[0] : $value]);

        $traceId = $headers->get('x-b3-traceid');
        $spanId = $headers->get('x-b3-spanid');
        $sampled = $headers->get('x-b3-sampled', '1') === '1';

        if ($traceId == null || $spanId == null) {
            return null;
        }

        $spanContext = SpanContext::createFromRemoteParent(
            traceId: $traceId,
            spanId: $spanId,
            traceFlags: $sampled ? SpanContextInterface::TRACE_FLAG_SAMPLED : SpanContextInterface::TRACE_FLAG_DEFAULT
        );

        if (! $spanContext->isValid()) {
            return null;
        }

        return Context::getCurrent()->withContextValue(AbstractSpan::wrap($spanContext));
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

    public function terminate(): void
    {
        $this->tracerProvider->shutdown();

        $this->startNewContext();
    }

    protected function startNewContext(): void
    {
        Context::getRoot()->activate();
    }
}
