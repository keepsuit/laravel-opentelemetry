<?php

namespace Keepsuit\LaravelOpenTelemetry\Facades;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Keepsuit\LaravelGrpc\GrpcRequest;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Symfony\Component\HttpFoundation\Response;

/**
 * @method static bool isRecording()
 * @method static string traceId()
 * @method static SpanInterface activeSpan()
 * @method static ScopeInterface activeScope()
 * @method static array activeSpanPropagationHeaders()
 * @method static SpanBuilderInterface build(string $name)
 * @method static SpanInterface start(string $name, int $spanKind = SpanKind::KIND_INTERNAL)
 * @method static mixed measure(string $name, \Closure $callback)
 * @method static mixed measureAsync(string $name, \Closure $callback)
 * @method static SpanInterface recordExceptionToSpan(SpanInterface $span, \Throwable $exception)
 * @method static SpanInterface recordHttpResponseToSpan(SpanInterface $span, Response $response)
 * @method static SpanInterface recordGrpcExceptionToSpan(SpanInterface $span, GRPCException $exception)
 * @method static SpanInterface recordGrpcSuccessResponseToSpan(SpanInterface $span)
 * @method static SpanInterface initFromHttpRequest(Request $request)
 * @method static SpanInterface initFromGrpcRequest(GrpcRequest $request)
 * @method static Context|null extractContextFromHttpRequest(Request $request)
 * @method static Context|null extractContextFromGrpcRequest(GrpcRequest $request)
 * @method static Context|null extractContextFromB3Headers(array $headers)
 * @method static SpanInterface startGrpcClientTracing(string $grpcFullName)
 */
class Tracer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Keepsuit\LaravelOpenTelemetry\Tracer::class;
    }
}
