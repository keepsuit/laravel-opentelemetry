<?php

namespace Keepsuit\LaravelOpenTelemetry\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Symfony\Component\HttpFoundation\Response;

class TraceRequest
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->is(config('opentelemetry.excluded_paths', []))) {
            return $next($request);
        }

        $span = Tracer::initFromHttpRequest($request);
        $scope = $span->activate();

        try {
            $response = $next($request);

            if ($response instanceof Response) {
                Tracer::recordHttpResponseToSpan($span, $response);
            }

            return $response;
        } catch (\Exception $exception) {
            Tracer::recordExceptionToSpan($span, $exception);

            throw $exception;
        } finally {
            $span->end();
            $scope->detach();
        }
    }
}
