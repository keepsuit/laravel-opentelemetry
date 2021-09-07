<?php

namespace Keepsuit\LaravelOpenTelemetry\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool isRecording()
 * @method static \Keepsuit\LaravelOpenTelemetry\Tracer start(string $name, ?\Closure $onStart = null, int $spanKind = \OpenTelemetry\Trace\SpanKind::KIND_INTERNAL)
 * @method static \Keepsuit\LaravelOpenTelemetry\Tracer stop(string $name, ?\Closure $onStop = null)
 * @method static \Keepsuit\LaravelOpenTelemetry\Tracer updateSpan(string $name, ?\Closure $callback = null)
 * @method static mixed measure(string $name, \Closure $callback = null, ?\Closure $onStart = null, ?\Closure $onStop = null)
 * @method static \OpenTelemetry\Trace\Span activeSpan()
 * @method static array activeSpanB3Headers()
 * @method static \Keepsuit\LaravelOpenTelemetry\Tracer initFromB3Headers(array $headers)
 * @method static \Keepsuit\LaravelOpenTelemetry\Tracer initFromRequest(\Illuminate\Http\Request $request)
 * @method static \Keepsuit\LaravelOpenTelemetry\Tracer initFromGrpcContext(\Spiral\GRPC\ContextInterface $context)
 * @method static \Keepsuit\LaravelOpenTelemetry\Tracer startGrpcClientTracing(string $grpcFullName)
 * @method static \Keepsuit\LaravelOpenTelemetry\Tracer stopGrpcClientTracing(string $grpcFullName, ?int $status = null)
 */
class Tracer extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Keepsuit\LaravelOpenTelemetry\Tracer::class;
    }
}
