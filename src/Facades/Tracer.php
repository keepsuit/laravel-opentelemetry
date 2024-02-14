<?php

namespace Keepsuit\LaravelOpenTelemetry\Facades;

use Illuminate\Support\Facades\Facade;
use Keepsuit\LaravelOpenTelemetry\Support\SpanBuilder;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;

/**
 * @method static string traceId()
 * @method static SpanInterface activeSpan()
 * @method static ScopeInterface|null activeScope()
 * @method static ContextInterface currentContext()
 * @method static array propagationHeaders(?ContextInterface $context = null)
 * @method static Context|null extractContextFromPropagationHeaders(array $headers)
 * @method static SpanBuilder newSpan(string $name)
 * @method static void updateLogContext()
 */
class Tracer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Keepsuit\LaravelOpenTelemetry\Tracer::class;
    }
}
