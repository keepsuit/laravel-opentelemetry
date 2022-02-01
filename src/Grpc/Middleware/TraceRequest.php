<?php

declare(strict_types=1);

namespace Keepsuit\LaravelOpenTelemetry\Grpc\Middleware;

use Keepsuit\LaravelGrpc\GrpcRequest;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;

class TraceRequest
{
    public function handle(GrpcRequest $request, \Closure $next)
    {
        if (in_array($request->service::class, config('opentelemetry.excluded_services', []), true)) {
            return $next($request);
        }

        $span = Tracer::initFromGrpcRequest($request);

        try {
            /** @var string $response */
            $response = $next($request);

            Tracer::recordGrpcSuccessResponseToSpan($span);

            return $response;
        } catch (GRPCException $e) {
            Tracer::recordGrpcExceptionToSpan($span, $e);

            throw $e;
        } finally {
            $span->end();
        }
    }
}
