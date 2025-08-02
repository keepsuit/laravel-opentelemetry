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

        $bootedTimestamp = $this->bootedTimestamp($request);

        $span = $this->startTracing($request, $bootedTimestamp);
        $scope = $span->activate();

        Tracer::updateLogContext();

        if ($bootedTimestamp) {
            Tracer::newSpan('app bootstrap')
                ->setStartTimestamp($bootedTimestamp)
                ->start()
                ->end();
        }

        try {
            $response = $next($request);

            if ($response instanceof Response) {
                $this->recordHttpResponseToSpan($span, $response);
            }

            return $response;
        } finally {
            Tracer::terminateActiveSpansUpToRoot($span);

            $scope->detach();
            $span->end();
        }
    }

    protected function startTracing(Request $request, ?int $startTimestamp = null): SpanInterface
    {
        $context = Tracer::extractContextFromPropagationHeaders($request->headers->all());

        /** @var non-empty-string $route */
        $route = rescue(fn () => Route::getRoutes()->match($request)->uri(), $request->path(), false);
        $route = str_starts_with($route, '/') ? $route : '/'.$route;

        $builder = Tracer::newSpan($route)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setParent($context)
            ->setAttribute(TraceAttributes::URL_FULL, $request->fullUrl())
            ->setAttribute(TraceAttributes::URL_PATH, $request->path() === '/' ? $request->path() : '/'.$request->path())
            ->setAttribute(TraceAttributes::URL_QUERY, $request->getQueryString())
            ->setAttribute(TraceAttributes::URL_SCHEME, $request->getScheme())
            ->setAttribute(TraceAttributes::HTTP_ROUTE, $route)
            ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->method())
            ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, $request->header('Content-Length'))
            ->setAttribute(TraceAttributes::SERVER_ADDRESS, $request->getHttpHost())
            ->setAttribute(TraceAttributes::SERVER_PORT, $request->getPort())
            ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->userAgent())
            ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $request->getProtocolVersion())
            ->setAttribute(TraceAttributes::NETWORK_PEER_ADDRESS, $request->ip());

        if ($startTimestamp) {
            $builder->setStartTimestamp($startTimestamp);
        }

        $span = $builder->start();

        $this->recordHeaders($span, $request);

        return $span;
    }

    protected function recordHttpResponseToSpan(SpanInterface $span, Response $response): void
    {
        $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());

        if (($content = $response->getContent()) !== false) {
            $span->setAttribute(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, strlen($content));
        }

        $this->recordHeaders($span, $response);

        if ($response->isSuccessful()) {
            $span->setStatus(StatusCode::STATUS_OK);
        }

        if ($response->isServerError() || $response->isClientError()) {
            $span->setStatus(StatusCode::STATUS_ERROR);
        }
    }

    protected function recordHeaders(SpanInterface $span, Request|Response $http): SpanInterface
    {
        $prefix = match (true) {
            $http instanceof Request => 'http.request.header.',
            $http instanceof Response => 'http.response.header.',
        };

        foreach ($http->headers->all() as $key => $value) {
            $key = strtolower($key);

            if (! HttpServerInstrumentation::headerIsAllowed($key)) {
                continue;
            }

            $value = HttpServerInstrumentation::headerIsSensitive($key) ? ['*****'] : $value;

            $span->setAttribute($prefix.$key, $value);
        }

        return $span;
    }

    /**
     * @return int|null Timestamp in nanoseconds when the application booted
     */
    protected function bootedTimestamp(Request $request): ?int
    {
        if ($request->server->has('REQUEST_TIME_FLOAT')) {
            return (int) (floatval($request->server('REQUEST_TIME_FLOAT')) * 1_000_000_000); // Convert seconds to nanoseconds
        }

        if (defined('LARAVEL_START')) {
            return (int) (LARAVEL_START * 1_000_000_000); // Convert seconds to nanoseconds
        }

        return null;
    }
}
