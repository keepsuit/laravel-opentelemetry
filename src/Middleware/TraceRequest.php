<?php

namespace Keepsuit\LaravelOpenTelemetry\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Sdk\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\Sdk\Trace\SamplingResult;
use OpenTelemetry\Trace\SpanKind;
use OpenTelemetry\Trace\Tracer;

class TraceRequest
{
    public function handle(Request $request, \Closure $next)
    {
        if (config('opentelemetry.exporter') === null) {
            return $next($request);
        }

        $sampler = new AlwaysOnSampler();
        $samplingResult = $sampler->shouldSample(
            Context::getCurrent(),
            md5((string)microtime(true)),
            'io.opentelemetry.example',
            SpanKind::KIND_INTERNAL
        );

        if ($samplingResult->getDecision() !== SamplingResult::RECORD_AND_SAMPLE) {
            return $next($request);
        }

        $tracer = $this->getTracer();

        $span = $tracer->startAndActivateSpan($request->path());

        /** @var Response $response */
        $response = $next($request);

        $span->setAttribute('http.status_code', $response->status());
        $span->setAttribute('http.method', $request->method());
        $span->setAttribute('http.url', $request->getUri());

        $span->end();

        return $response;
    }

    protected function getTracer(): Tracer
    {
        return app(Tracer::class);
    }
}
