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
                    ->setAttribute(TraceAttributes::HTTP_METHOD, $request->getMethod())
                    ->setAttribute(TraceAttributes::HTTP_FLAVOR, $request->getProtocolVersion())
                    ->setAttribute(TraceAttributes::HTTP_URL, (string) $request->getUri())
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_CONTENT_LENGTH, $request->getBody()->getSize())
                    ->startSpan();
                $scope = $span->activate();

                foreach (Tracer::propagationHeaders() as $key => $value) {
                    $request = $request->withHeader($key, $value);
                }

                $promise = $handler($request, $options);
                assert($promise instanceof PromiseInterface);

                return $promise->then(function (Response $response) use ($scope, $span) {
                    $span->setAttribute(TraceAttributes::HTTP_STATUS_CODE, $response->getStatusCode());

                    if ($response->getStatusCode() >= 400) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }

                    $span->end();
                    $scope->detach();

                    return $response;
                });
            };
        };
    }
}
