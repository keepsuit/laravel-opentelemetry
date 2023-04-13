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
                        TraceAttributes::HTTP_METHOD => $request->getMethod(),
                        TraceAttributes::HTTP_FLAVOR => $request->getProtocolVersion(),
                        TraceAttributes::HTTP_URL => sprintf('%s://%s%s', $request->getUri()->getScheme(), $request->getUri()->getHost(), $request->getUri()->getPath()),
                        TraceAttributes::HTTP_TARGET => $request->getUri()->getPath(),
                        TraceAttributes::HTTP_HOST => $request->getUri()->getHost(),
                        TraceAttributes::HTTP_SCHEME => $request->getUri()->getScheme(),
                        TraceAttributes::HTTP_REQUEST_CONTENT_LENGTH => $request->getBody()->getSize(),
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
                        TraceAttributes::HTTP_STATUS_CODE => $response->getStatusCode(),
                        TraceAttributes::HTTP_RESPONSE_CONTENT_LENGTH => $response->getHeader('Content-Length')[0] ?? null,
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
