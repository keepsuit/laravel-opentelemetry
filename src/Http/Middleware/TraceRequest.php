<?php

namespace Keepsuit\LaravelOpenTelemetry\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Component\HttpFoundation\Response;

class TraceRequest
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is(config('opentelemetry.excluded_paths', []))) {
            return $next($request);
        }

        Tracer::initFromRequest($request);

        /** @var non-empty-string $route */
        $route = rescue(fn () => Route::getRoutes()->match($request)->uri(), $request->path(), false);
        $route = str_starts_with($route, '/') ? $route : '/'.$route;

        $span = Tracer::start(name: $route, spanKind: SpanKind::KIND_SERVER);

        $span->setAttribute('http.method', $request->method())
            ->setAttribute('http.url', $request->getUri())
            ->setAttribute('http.target', $request->getRequestUri())
            ->setAttribute('http.route', $route)
            ->setAttribute('http.host', $request->getHttpHost())
            ->setAttribute('http.scheme', $request->getScheme())
            ->setAttribute('http.user_agent', $request->userAgent())
            ->setAttribute('http.request_content_length', $request->header('Content-Length'));

        try {
            $response = $next($request);

            if ($response instanceof Response) {
                $span->setAttribute('http.status_code', $response->getStatusCode())
                    ->setAttribute('http.response_content_length', strlen($response->getContent()));

                if ($response->isSuccessful()) {
                    $span->setStatus(StatusCode::STATUS_OK);
                }

                if ($response->isServerError() || $response->isClientError()) {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                    $span->setAttribute('error', true);
                }
            }

            $span->end();

            return $response;
        } catch (\Exception $exception) {
            $span->recordException($exception)
                ->setStatus(StatusCode::STATUS_ERROR)
                ->setAttribute('error', true)
                ->end();

            throw $exception;
        }
    }
}
