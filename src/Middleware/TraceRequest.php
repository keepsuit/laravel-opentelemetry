<?php

namespace Keepsuit\LaravelOpenTelemetry\Middleware;

use Illuminate\Http\Request;
use OpenTelemetry\Trace\Tracer;

class TraceRequest
{
    public function handle(Request $request, \Closure $next)
    {
        if (config('opentelemetry.exporter') === null) {
            return $next($request);
        }

        $tracer = $this->getTracer();

        $tracer->startAndActivateSpan($request->getUri());

        $response = $next($request);

        $tracer->endActiveSpan();

        return $response;
    }

    protected function getTracer(): Tracer
    {
        return app(Tracer::class);
    }
}
