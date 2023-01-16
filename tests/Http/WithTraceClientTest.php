<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;

beforeEach(function () {
    Http::fake();
});

it('injects propagation headers to Http client request', function () {
    $traceParent = Tracer::measure('parent', function () {
        Http::withTrace()->post('https://example.com/test');

        return Tracer::activeSpanPropagationHeaders()['traceparent'];
    });

    Http::assertSent(function (Request $request) use ($traceParent) {
        expect($request)
            ->hasHeader('traceparent')->toBeTrue()
            ->header('traceparent')->toBe([$traceParent]);

        return true;
    });
});

it('skip injection if no active span', function () {
    Http::withTrace()->post('https://example.com/test');

    Http::assertSent(function (Request $request) {
        expect($request)
            ->hasHeader('traceparent')->toBeFalse();

        return true;
    });
});
