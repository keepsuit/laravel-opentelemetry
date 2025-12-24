<?php

namespace Keepsuit\LaravelOpenTelemetry\Support\HttpServer;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\HttpServerInstrumentation;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\Attributes\ClientAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Attributes\UserAgentAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use Symfony\Component\HttpFoundation\Response;

class TraceRequestMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->is(HttpServerInstrumentation::getExcludedPaths())) {
            return $next($request);
        }

        if (in_array($request->method(), HttpServerInstrumentation::getExcludedMethods(), true)) {
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
            $this->recordHttpRequestToSpan($span, $request);

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
            ->setAttribute(HttpAttributes::HTTP_ROUTE, $route);

        if ($startTimestamp) {
            $builder->setStartTimestamp($startTimestamp);
        }

        $span = $builder->start();

        $this->recordHeaders($span, $request);

        return $span;
    }

    protected function recordHttpResponseToSpan(SpanInterface $span, Response $response): void
    {
        $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());

        if (($content = $response->getContent()) !== false) {
            $span->setAttribute(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE, strlen($content));
        }

        $this->recordHeaders($span, $response);

        if ($response->isSuccessful()) {
            $span->setStatus(StatusCode::STATUS_OK);
        }

        if ($response->isServerError() || $response->isClientError()) {
            $span->setStatus(StatusCode::STATUS_ERROR);
        }
    }

    protected function recordHttpRequestToSpan(SpanInterface $span, Request $request): void
    {
        $protocolVersion = Str::of($request->getProtocolVersion() ?? '');

        $span
            ->setAttribute(UrlAttributes::URL_FULL, $request->fullUrl())
            ->setAttribute(UrlAttributes::URL_PATH, $request->path() === '/' ? $request->path() : '/'.$request->path())
            ->setAttribute(UrlAttributes::URL_QUERY, $request->getQueryString())
            ->setAttribute(UrlAttributes::URL_SCHEME, $request->getScheme())
            ->setAttribute(UserAgentAttributes::USER_AGENT_ORIGINAL, $request->userAgent())
            ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $request->method())
            ->setAttribute(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE, $request->header('Content-Length'))
            ->setAttribute(ServerAttributes::SERVER_ADDRESS, $request->getHttpHost())
            ->setAttribute(ServerAttributes::SERVER_PORT, $request->getPort())
            ->setAttribute(NetworkAttributes::NETWORK_PROTOCOL_NAME, 'http')
            ->setAttribute(NetworkAttributes::NETWORK_PROTOCOL_VERSION, match (true) {
                $protocolVersion->isEmpty() => null,
                $protocolVersion->contains('/') => $protocolVersion->after('/')->toString(),
                default => $protocolVersion->toString(),
            })
            ->setAttribute(NetworkAttributes::NETWORK_PEER_ADDRESS, $request->server('REMOTE_ADDR'))
            ->setAttribute(ClientAttributes::CLIENT_ADDRESS, $request->ip());
    }

    protected function recordHeaders(SpanInterface $span, Request|Response $http): void
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
