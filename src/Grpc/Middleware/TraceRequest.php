<?php

declare(strict_types=1);

namespace Keepsuit\LaravelOpenTelemetry\Grpc\Middleware;

use Keepsuit\LaravelGrpc\GrpcRequest;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\Sdk\Trace\SpanStatus;
use OpenTelemetry\Trace\Span;
use OpenTelemetry\Trace\SpanKind;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\StatusCode;

class TraceRequest
{
    public function handle(GrpcRequest $request, \Closure $next)
    {
        if (in_array($request->service::class, config('opentelemetry.excluded_services', []), true)) {
            return $next($request);
        }

        Tracer::initFromGrpcContext($request->context);

        $traceName = sprintf('%s/%s', $request->getServiceName() ?? 'Unknown', $request->getMethodName());

        Tracer::start(
            name: $traceName,
            spanKind: SpanKind::KIND_SERVER,
            onStart: function (Span $span) use ($request): void {
                $span->setAttribute('rpc.system', 'grpc');
                $span->setAttribute('rpc.service', $request->getServiceName());
                $span->setAttribute('rpc.method', $request->getMethodName());
                $span->setAttribute('grpc.service', $request->getServiceName());
                $span->setAttribute('grpc.method', $request->method->getName());
                $span->setAttribute('net.peer.name', $request->context->getValue(':authority'));
            }
        );

        try {
            /** @var string $response */
            $response = $next($request);

            Tracer::stop($traceName, function (Span $span) {
                $span->setAttribute('rpc.grpc.status_code', StatusCode::OK);
            });

            return $response;
        } catch (GRPCException $e) {
            Tracer::stop($traceName, function (Span $span) use ($e) {
                $span->setAttribute('rpc.grpc.status_code', $e->getCode());
                $span->setSpanStatus(SpanStatus::ERROR);
                $span->setAttribute('error', true);
            });

            throw $e;
        }
    }
}
