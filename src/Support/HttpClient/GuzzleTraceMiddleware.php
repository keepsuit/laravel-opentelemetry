<?php

namespace Keepsuit\LaravelOpenTelemetry\Support\HttpClient;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Http\Message\RequestInterface;

class GuzzleTraceMiddleware
{
    public static function make(): Closure
    {
        return static function (callable $handler): callable {
            return static function (RequestInterface $request, array $options) use ($handler) {
                $span = Tracer::build(sprintf('HTTP %s', $request->getMethod()))
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttributes([
                        TraceAttributes::URL_FULL => sprintf('%s://%s%s', $request->getUri()->getScheme(), $request->getUri()->getHost(), $request->getUri()->getPath()),
                        TraceAttributes::URL_PATH => $request->getUri()->getPath(),
                        TraceAttributes::URL_QUERY => $request->getUri()->getQuery(),
                        TraceAttributes::HTTP_REQUEST_METHOD => $request->getMethod(),
                        TraceAttributes::HTTP_REQUEST_BODY_SIZE => $request->getBody()->getSize(),
                        TraceAttributes::URL_SCHEME => $request->getUri()->getScheme(),
                        TraceAttributes::SERVER_ADDRESS => $request->getUri()->getHost(),
                        TraceAttributes::SERVER_PORT => $request->getUri()->getPort(),
                    ])
                    ->startSpan();

                $context = $span->storeInContext(Tracer::currentContext());

                foreach (Tracer::propagationHeaders($context) as $key => $value) {
                    $request = $request->withHeader($key, $value);
                }

                $promise = $handler($request, $options);
                assert($promise instanceof PromiseInterface);

                return $promise->then(function (Response $response) use ($span) {
                    $span->setAttributes([
                        TraceAttributes::HTTP_RESPONSE_STATUS_CODE => $response->getStatusCode(),
                        TraceAttributes::HTTP_REQUEST_BODY_SIZE => $response->getHeader('Content-Length')[0] ?? null,
                    ]);

                    if ($response->getStatusCode() >= 400) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }

                    $span->end();

                    return $response;
                });
            };
        };
    }
}
