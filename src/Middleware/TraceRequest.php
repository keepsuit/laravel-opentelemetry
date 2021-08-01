<?php

namespace Keepsuit\LaravelOpenTelemetry\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenTelemetry\Trace\Tracer;

class TraceRequest
{
    public function handle(Request $request, \Closure $next)
    {
        if (config('opentelemetry.exporter') === null) {
            return $next($request);
        }

        $tracer = $this->getTracer();

        $span = $tracer->startAndActivateSpan($request->getUri());

        /** @var Response $response */
        $response = $next($request);

        $span->setAttribute('http.status_code', $response->status());
        $span->setAttribute('http.method', $request->method());
        $span->setAttribute('http.url', $request->getUri());

        $span->end();

        return $response;
    }

    protected function getTracer(): Tracer
    {
        return app(Tracer::class);
    }
}
