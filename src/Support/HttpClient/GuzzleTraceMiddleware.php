<?php

namespace Keepsuit\LaravelOpenTelemetry\Support\HttpClient;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Psr\Http\Message\RequestInterface;

class GuzzleTraceMiddleware
{
    public static function make(): Closure
    {
        return static function (callable $handler): callable {
            return static function (RequestInterface $request, array $options) use ($handler) {
                $span = Tracer::build(sprintf('HTTP %s', $request->getMethod()))
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute('http.method', $request->getMethod())
                    ->setAttribute('http.flavor', $request->getProtocolVersion())
                    ->setAttribute('http.url', (string) $request->getUri())
                    ->setAttribute('http.request_content_length', $request->getBody()->getSize())
                    ->startSpan();
                $scope = $span->activate();

                foreach (Tracer::propagationHeaders() as $key => $value) {
                    $request = $request->withHeader($key, $value);
                }

                $promise = $handler($request, $options);
                assert($promise instanceof PromiseInterface);

                return $promise->then(function (Response $response) use ($scope, $span) {
                    $span->setAttribute('http.status_code', $response->getStatusCode());

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
