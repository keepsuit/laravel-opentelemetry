<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Closure;
use Exception;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Keepsuit\LaravelGrpc\GrpcRequest;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\Span;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Symfony\Component\HttpFoundation\Response;

class Tracer
{
    protected TracerInterface $tracer;

    protected ?ScopeInterface $rootScope = null;

    public function __construct(protected TracerProvider $tracerProvider, protected TextMapPropagatorInterface $propagator)
    {
        $this->tracer = $this->tracerProvider->getTracer('io.opentelemetry.contrib.php');
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
     * @param  non-empty-string                 $name
     * @param  Closure(SpanInterface $span): U  $callback
     * @throws Exception
     * @return U
     */
    public function measure(string $name, Closure $callback)
    {
        $span = $this->start($name, SpanKind::KIND_INTERNAL);

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

    /**
     * @template U
     *
     * @param  non-empty-string                 $name
     * @param  Closure(SpanInterface $span): U  $callback
     * @throws Exception
     * @return U
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
        return Span::getCurrent();
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

        $builder = $this->build(name: $route, spanKind: SpanKind::KIND_SERVER);

        $builder->setParent($context);

        $span = $builder->startSpan();

        $this->rootScope?->detach();

        $this->rootScope = $span->activate();

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

        $builder = $this->build(name: $traceName, spanKind: SpanKind::KIND_SERVER);

        $builder->setParent($context);

        $span = $builder->startSpan();

        $this->rootScope?->detach();

        $this->rootScope = $span->activate();

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
        if (config('opentelemetry.exporter') === null) {
            return false;
        }

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
        $this->rootScope?->detach();

        $this->tracerProvider->shutdown();
    }
}
