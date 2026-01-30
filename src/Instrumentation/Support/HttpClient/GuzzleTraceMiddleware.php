<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation\Support\HttpClient;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Keepsuit\LaravelOpenTelemetry\Facades\Meter;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\HttpClientInstrumentation;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\UrlIncubatingAttributes;
use OpenTelemetry\SemConv\Metrics\HttpMetrics;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class GuzzleTraceMiddleware
{
    public static function make(): Closure
    {
        return static function (callable $handler): callable {
            return static function (RequestInterface $request, array $options) use ($handler) {
                if (! Tracer::traceStarted()) {
                    return $handler($request, $options);
                }

                $requestStartedAt = Clock::getDefault()->now();

                $route = HttpClientInstrumentation::routeName($request);

                $span = Tracer::newSpan(trim(sprintf('%s %s', $request->getMethod(), $route ?? '')))
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->start();

                static::recordHeaders($span, $request);

                $context = $span->storeInContext(Tracer::currentContext());

                foreach (Tracer::propagationHeaders($context) as $key => $value) {
                    $request = $request->withHeader($key, $value);
                }

                $promise = $handler($request, $options);
                assert($promise instanceof PromiseInterface);

                return $promise->then(function (ResponseInterface $response) use ($request, $requestStartedAt, $span) {
                    $sharedAttributes = static::sharedTraceMetricAttributes($request, $response);

                    $span->setAttributes($sharedAttributes);

                    $redactedQueryString = HttpClientInstrumentation::redactQueryString($request->getUri()->getQuery());

                    $fullUrl = sprintf('%s://%s%s', $request->getUri()->getScheme(), $request->getUri()->getHost(), $request->getUri()->getPath());
                    $fullUrl = $redactedQueryString === '' ? $fullUrl : sprintf('%s?%s', $fullUrl, $redactedQueryString);

                    $span->setAttribute(UrlAttributes::URL_FULL, $fullUrl)
                        ->setAttribute(UrlAttributes::URL_PATH, $request->getUri()->getPath())
                        ->setAttribute(UrlAttributes::URL_QUERY, $redactedQueryString)
                        ->setAttribute(UrlAttributes::URL_SCHEME, $request->getUri()->getScheme())
                        ->setAttribute(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE, $request->getBody()->getSize());

                    if (($contentLength = $response->getHeader('Content-Length')[0] ?? null) !== null) {
                        $span->setAttribute(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE, $contentLength);
                    }

                    static::recordHeaders($span, $response);

                    if ($response->getStatusCode() >= 400 && $response->getStatusCode() <= 599) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }

                    $span->end();

                    static::recordRequestDurationMetric($requestStartedAt, $sharedAttributes);

                    return $response;
                });
            };
        };
    }

    protected static function recordHeaders(SpanInterface $span, RequestInterface|ResponseInterface $http): SpanInterface
    {
        $prefix = match (true) {
            $http instanceof RequestInterface => 'http.request.header.',
            $http instanceof ResponseInterface => 'http.response.header.',
        };

        foreach ($http->getHeaders() as $key => $value) {
            $key = strtolower($key);

            if (! HttpClientInstrumentation::headerIsAllowed($key)) {
                continue;
            }

            $value = HttpClientInstrumentation::headerIsSensitive($key) ? ['*****'] : $value;

            $span->setAttribute($prefix.$key, $value);
        }

        return $span;
    }

    /**
     * @param  array<non-empty-string, bool|int|float|string|array|null>  $attributes
     */
    protected static function recordRequestDurationMetric(int $requestStartedAt, array $attributes): void
    {
        $duration = (Clock::getDefault()->now() - $requestStartedAt) / ClockInterface::NANOS_PER_SECOND;

        // @see https://opentelemetry.io/docs/specs/semconv/http/http-metrics/#metric-httpclientrequestduration
        Meter::histogram(
            name: HttpMetrics::HTTP_CLIENT_REQUEST_DURATION,
            unit: 's',
            description: 'Duration of HTTP client requests.',
            advisory: [
                'ExplicitBucketBoundaries' => [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1.0, 2.5, 5.0, 7.5, 10.0],
            ])
            ->record($duration, $attributes);
    }

    /**
     * @return array<non-empty-string, bool|int|float|string|array|null>
     */
    protected static function sharedTraceMetricAttributes(RequestInterface $request, ResponseInterface $response): array
    {
        $route = HttpClientInstrumentation::routeName($request);

        return [
            UrlAttributes::URL_SCHEME => $request->getUri()->getScheme(),
            HttpAttributes::HTTP_REQUEST_METHOD => $request->getMethod(),
            HttpAttributes::HTTP_RESPONSE_STATUS_CODE => $response->getStatusCode(),
            UrlIncubatingAttributes::URL_TEMPLATE => $route,
            ErrorAttributes::ERROR_TYPE => $response->getStatusCode() >= 400 && $response->getStatusCode() <= 599 ? (string) $response->getStatusCode() : null,
            NetworkAttributes::NETWORK_PROTOCOL_NAME => 'http',
            NetworkAttributes::NETWORK_PROTOCOL_VERSION => $response->getProtocolVersion(),
            ServerAttributes::SERVER_ADDRESS => $request->getUri()->getHost(),
            ServerAttributes::SERVER_PORT => $request->getUri()->getPort(),
        ];
    }
}
