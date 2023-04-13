<?php

namespace Keepsuit\LaravelOpenTelemetry\Facades;

use Illuminate\Support\Facades\Facade;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;

/**
 * @method static bool isRecording()
 * @method static string traceId()
 * @method static SpanInterface activeSpan()
 * @method static ScopeInterface|null activeScope()
 * @method static ContextInterface currentContext()
 * @method static array propagationHeaders(?ContextInterface $context = null)
 * @method static SpanBuilderInterface build(string $name)
 * @method static SpanInterface start(string $name, int $spanKind = SpanKind::KIND_INTERNAL)
 * @method static mixed measure(string $name, \Closure $callback)
 * @method static mixed measureAsync(string $name, \Closure $callback)
 * @method static SpanInterface recordExceptionToSpan(SpanInterface $span, \Throwable $exception)
 * @method static Context|null extractContextFromPropagationHeaders(array $headers)
 * @method static void setRootSpan(SpanInterface $span)
 */
class Tracer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Keepsuit\LaravelOpenTelemetry\Tracer::class;
    }
}
