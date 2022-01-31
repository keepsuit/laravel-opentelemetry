<?php

declare(strict_types=1);

namespace Keepsuit\LaravelOpenTelemetry\Grpc\Middleware;

use Keepsuit\LaravelGrpc\GrpcRequest;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Trace\SpanKind;
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

        $span = Tracer::start(name: $traceName, spanKind: SpanKind::KIND_SERVER);
        $span->activate();

        $span->setAttribute('rpc.system', 'grpc')
            ->setAttribute('rpc.service', $request->getServiceName())
            ->setAttribute('rpc.method', $request->getMethodName())
            ->setAttribute('grpc.service', $request->getServiceName())
            ->setAttribute('grpc.method', $request->method->getName())
            ->setAttribute('net.peer.name', $request->context->getValue(':authority'));

        try {
            /** @var string $response */
            $response = $next($request);

            $span->setAttribute('rpc.grpc.status_code', StatusCode::OK)
                ->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_OK)
                ->end();

            return $response;
        } catch (GRPCException $e) {
            $span->recordException($e)
                ->setAttribute('rpc.grpc.status_code', $e->getCode())
                ->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR)
                ->setAttribute('error', true)
                ->end();

            throw $e;
        }
    }
}
