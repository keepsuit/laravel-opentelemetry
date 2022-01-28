<?php

namespace Keepsuit\LaravelOpenTelemetry\Facades;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use Spiral\RoadRunner\GRPC\ContextInterface;

/**
 * @method static SpanBuilderInterface build(string $name, int $spanKind = SpanKind::KIND_INTERNAL)
 * @method static SpanInterface start(string $name, int $spanKind = SpanKind::KIND_INTERNAL)
 * @method static mixed measure(string $name, \Closure $callback)
 * @method static SpanInterface activeSpan()
 * @method static array activeSpanB3Headers()
 * @method static \Keepsuit\LaravelOpenTelemetry\Tracer initFromB3Headers(array $headers)
 * @method static \Keepsuit\LaravelOpenTelemetry\Tracer initFromRequest(Request $request)
 * @method static \Keepsuit\LaravelOpenTelemetry\Tracer initFromGrpcContext(ContextInterface $context)
 * @method static SpanInterface startGrpcClientTracing(string $grpcFullName)
 */
class Tracer extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Keepsuit\LaravelOpenTelemetry\Tracer::class;
    }
}
