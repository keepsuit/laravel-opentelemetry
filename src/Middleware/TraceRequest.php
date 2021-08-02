<?php

namespace Keepsuit\LaravelOpenTelemetry\Middleware;

use Illuminate\Http\Request;
use OpenTelemetry\Trace\SpanStatus;
use OpenTelemetry\Trace\Tracer;
use Symfony\Component\HttpFoundation\Response;

class TraceRequest
{
    public function handle(Request $request, \Closure $next)
    {
        if (config('opentelemetry.exporter') === null) {
            return $next($request);
        }

        $tracer = $this->getTracer();

        $span = $tracer->startAndActivateSpan($request->path());

        $span->setAttribute('http.method', $request->method());
        $span->setAttribute('http.path', $request->path());
        $span->setAttribute('http.url', $request->getUri());

        /** @var \Symfony\Component\HttpFoundation\Response $response */
        $response = $next($request);

        if ($response instanceof Response) {
            $span->setAttribute('http.status_code', $response->getStatusCode());

            if ($span->getStatus()->getCanonicalStatusCode() === SpanStatus::UNSET) {
                if ($response->isSuccessful()) {
                    $span->setSpanStatus(SpanStatus::OK);
                }
                if ($response->isServerError() || $response->isClientError()) {
                    $span->setSpanStatus(SpanStatus::ERROR);
                }
            }
        }

        $span->end();

        return $response;
    }

    protected function getTracer(): Tracer
    {
        return app(Tracer::class);
    }
}
