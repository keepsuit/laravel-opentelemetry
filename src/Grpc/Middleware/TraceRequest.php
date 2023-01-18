<?php

declare(strict_types=1);

namespace Keepsuit\LaravelOpenTelemetry\Grpc\Middleware;

use Closure;
use Keepsuit\LaravelGrpc\GrpcRequest;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;

class TraceRequest
{
    public function handle(GrpcRequest $request, Closure $next): mixed
    {
        if (in_array($request->service::class, config('opentelemetry.excluded_services', []), true)) {
            return $next($request);
        }

        $span = $this->startTracing($request);
        $scope = $span->activate();

        try {
            /** @var string $response */
            $response = $next($request);

            $this->recordGrpcSuccessResponseToSpan($span);

            return $response;
        } catch (GRPCException $e) {
            $this->recordGrpcExceptionToSpan($span, $e);

            throw $e;
        } finally {
            $span->end();
            $scope->detach();
        }
    }

    protected function startTracing(GrpcRequest $request): SpanInterface
    {
        $context = Tracer::extractContextFromPropagationHeaders($request->context->getValues());

        $traceName = sprintf('%s/%s', $request->getServiceName() ?? 'Unknown', $request->getMethodName());

        $span = Tracer::build(name: $traceName)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setParent($context)
            ->startSpan();

        Tracer::setRootSpan($span);

        $span->setAttribute('rpc.system', 'grpc')
            ->setAttribute('rpc.service', $request->getServiceName())
            ->setAttribute('rpc.method', $request->getMethodName())
            ->setAttribute('grpc.service', $request->getServiceName())
            ->setAttribute('grpc.method', $request->method->getName())
            ->setAttribute('net.peer.name', $request->context->getValue(':authority'));

        return $span;
    }

    protected function recordGrpcSuccessResponseToSpan(SpanInterface $span): void
    {
        $span->setAttribute('rpc.grpc.status_code', \Spiral\RoadRunner\GRPC\StatusCode::OK)
            ->setStatus(StatusCode::STATUS_OK);
    }

    protected function recordGrpcExceptionToSpan(SpanInterface $span, GRPCException|\Exception $exception): void
    {
        $span->recordException($exception)
            ->setAttribute('rpc.grpc.status_code', $exception->getCode())
            ->setStatus(StatusCode::STATUS_ERROR);
    }
}
