<?php

namespace Keepsuit\LaravelOpenTelemetry\Middleware;

use Closure;
use Illuminate\Http\Request;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\Trace\Span;
use OpenTelemetry\Trace\SpanStatus;
use Symfony\Component\HttpFoundation\Response;

class TraceRequest
{
    public function handle(Request $request, Closure $next)
    {
        if (config('opentelemetry.exporter', null) === null) {
            return $next($request);
        }

        if ($request->is(config('opentelemetry.excluded_paths', []))) {
            return $next($request);
        }

        Tracer::start($request->path(), function (Span $span) use ($request) {
            $span->setAttribute('http.method', $request->method());
            $span->setAttribute('http.path', $request->path());
            $span->setAttribute('http.url', $request->getUri());
        });

        /** @var Response $response */
        $response = $next($request);

        Tracer::stop($request->path(), function (Span $span) use ($response) {
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
        });

        return $response;
    }
}
