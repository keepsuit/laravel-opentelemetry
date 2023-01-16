<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Closure;
use Exception;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Keepsuit\LaravelGrpc\GrpcRequest;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\Span;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Symfony\Component\HttpFoundation\Response;
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

    public function activeSpanPropagationHeaders(): array
    {
        $headers = [];

        $this->propagator->inject($headers);

        return $headers;
    }

    public function initFromHttpRequest(Request $request): SpanInterface
    {
        $context = $this->propagator->extract($request->headers->all());

        /** @var non-empty-string $route */
        $route = rescue(fn () => Route::getRoutes()->match($request)->uri(), $request->path(), false);
        $route = str_starts_with($route, '/') ? $route : '/'.$route;

        $span = $this->build(name: $route)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setParent($context)
            ->startSpan();

        $this->setTraceIdForLogs($span);

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

    public function initFromGrpcRequest(GrpcRequest $request): SpanInterface
    {
        $context = $this->propagator->extract($request->context->getValues());

        $traceName = sprintf('%s/%s', $request->getServiceName() ?? 'Unknown', $request->getMethodName());

        $span = $this->build(name: $traceName)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setParent($context)
            ->startSpan();

        $this->setTraceIdForLogs($span);

        $span->setAttribute('rpc.system', 'grpc')
            ->setAttribute('rpc.service', $request->getServiceName())
            ->setAttribute('rpc.method', $request->getMethodName())
            ->setAttribute('grpc.service', $request->getServiceName())
            ->setAttribute('grpc.method', $request->method->getName())
            ->setAttribute('net.peer.name', $request->context->getValue(':authority'));

        return $span;
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

    /**
     * @phpstan-param  non-empty-string $grpcFullName Format <package>.<serviceName>/<methodName>
     */
    public function startGrpcClientTracing(string $grpcFullName): SpanInterface
    {
        [$serviceName, $methodName] = explode('/', $grpcFullName, 2);

        return $this->build($grpcFullName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('rpc.system', 'grpc')
            ->setAttribute('rpc.service', $serviceName)
            ->setAttribute('rpc.method', $methodName)
            ->setAttribute('grpc.service', $serviceName)
            ->setAttribute('grpc.method', $methodName)
            ->startSpan();
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
