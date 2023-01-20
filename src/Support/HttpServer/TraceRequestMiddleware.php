<?php

namespace Keepsuit\LaravelOpenTelemetry\Support\HttpServer;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\HttpServerInstrumentation;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\HttpFoundation\Response;

class TraceRequestMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->is(HttpServerInstrumentation::getExcludedPaths())) {
            return $next($request);
        }

        $span = $this->startTracing($request);
        $scope = $span->activate();

        try {
            $response = $next($request);

            if ($response instanceof Response) {
                $this->recordHttpResponseToSpan($span, $response);
            }

            return $response;
        } catch (\Throwable $exception) {
            Tracer::recordExceptionToSpan($span, $exception);

            throw $exception;
        } finally {
            $span->end();
            $scope->detach();
        }
    }

    protected function startTracing(Request $request): SpanInterface
    {
        $context = Tracer::extractContextFromPropagationHeaders($request->headers->all());

        /** @var non-empty-string $route */
        $route = rescue(fn () => Route::getRoutes()->match($request)->uri(), $request->path(), false);
        $route = str_starts_with($route, '/') ? $route : '/'.$route;

        $span = Tracer::build(name: $route)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setParent($context)
            ->startSpan();

        Tracer::setRootSpan($span);

        $span->setAttribute(TraceAttributes::HTTP_METHOD, $request->method())
            ->setAttribute(TraceAttributes::HTTP_URL, $request->getUri())
            ->setAttribute(TraceAttributes::HTTP_TARGET, $request->getRequestUri())
            ->setAttribute(TraceAttributes::HTTP_ROUTE, $route)
            ->setAttribute(TraceAttributes::HTTP_HOST, $request->getHttpHost())
            ->setAttribute(TraceAttributes::HTTP_SCHEME, $request->getScheme())
            ->setAttribute(TraceAttributes::HTTP_USER_AGENT, $request->userAgent())
            ->setAttribute(TraceAttributes::HTTP_CLIENT_IP, $request->ip())
            ->setAttribute(TraceAttributes::HTTP_REQUEST_CONTENT_LENGTH, $request->header('Content-Length'));

        return $span;
    }

    protected function recordHttpResponseToSpan(SpanInterface $span, Response $response): void
    {
        $span->setAttribute(TraceAttributes::HTTP_STATUS_CODE, $response->getStatusCode())
            ->setAttribute(TraceAttributes::HTTP_RESPONSE_CONTENT_LENGTH, strlen($response->getContent()));

        if ($response->isSuccessful()) {
            $span->setStatus(StatusCode::STATUS_OK);
        }

        if ($response->isServerError() || $response->isClientError()) {
            $span->setStatus(StatusCode::STATUS_ERROR);
        }
    }
}
