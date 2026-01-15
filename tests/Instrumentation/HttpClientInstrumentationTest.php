<?php

use GuzzleHttp\Server\Server;
use Illuminate\Support\Facades\Http;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\HttpClientInstrumentation;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

test('http client span is not created when trace is not started', function () {
    registerInstrumentation(HttpClientInstrumentation::class);

    expect(Tracer::traceStarted())->toBeFalse();

    Http::fake([
        '*' => Http::response('', 200, ['Content-Length' => 0]),
    ]);

    Http::withTrace()->get(Server::$url);

    $span = getRecordedSpans()->first();

    expect($span)->toBeNull();
});

it('injects propagation headers to all client requests', function () {
    registerInstrumentation(HttpClientInstrumentation::class);

    Http::fake([
        '*' => Http::response('', 200, ['Content-Length' => 0]),
    ]);

    $traceId = withRootSpan(function () {
        Http::get(Server::$url);

        return Tracer::traceId();
    });

    $httpSpan = getRecordedSpans()->first();

    $request = Http::recorded()->first()[0];
    assert($request instanceof \Illuminate\Http\Client\Request);

    expect($request)
        ->header('traceparent')->toBe([sprintf('00-%s-%s-01', $traceId, $httpSpan->getSpanId())]);
});

test('doesnt auto inject propagation headers when manual', function () {
    registerInstrumentation(HttpClientInstrumentation::class, [
        'manual' => true,
    ]);

    Http::fake([
        '*' => Http::response('', 200, ['Content-Length' => 0]),
    ]);

    withRootSpan(function () {
        Http::get(Server::$url);

        return Tracer::traceId();
    });

    expect(getRecordedSpans())
        ->toHaveCount(1)
        ->first()->getName()->toBe('root');
});

it('injects propagation headers manually to client request', function () {
    registerInstrumentation(HttpClientInstrumentation::class, [
        'manual' => true,
    ]);

    Http::fake([
        '*' => Http::response('', 200, ['Content-Length' => 0]),
    ]);

    $traceId = withRootSpan(function () {
        Http::withTrace()->get(Server::$url);

        return Tracer::traceId();
    });

    $httpSpan = getRecordedSpans()->first();

    $request = Http::recorded()->first()[0];
    assert($request instanceof \Illuminate\Http\Client\Request);

    expect($request)
        ->header('traceparent')->toBe([sprintf('00-%s-%s-01', $traceId, $httpSpan->getSpanId())]);
});

it('create http client span', function (array $options, bool $withTrace) {
    registerInstrumentation(HttpClientInstrumentation::class, $options);

    Http::fake([
        '*' => Http::response('', 200, ['Content-Length' => 0]),
    ]);

    withRootSpan(function () use ($withTrace) {
        if ($withTrace) {
            Http::withTrace()->get(Server::$url);
        } else {
            Http::get(Server::$url);
        }
    });

    expect(getRecordedSpans()->count())->toBe(2);

    $httpSpan = getRecordedSpans()->first();

    expect($httpSpan)
        ->getKind()->toBe(SpanKind::KIND_CLIENT)
        ->getName()->toBe('GET')
        ->getStatus()->getCode()->toBe(StatusCode::STATUS_UNSET)
        ->getAttributes()->toMatchArray([
            'url.full' => 'http://127.0.0.1/',
            'url.path' => '/',
            'url.query' => '',
            'http.request.method' => 'GET',
            'http.request.body.size' => '0',
            'url.scheme' => 'http',
            'server.address' => '127.0.0.1',
            'server.port' => 8126,
            'http.response.status_code' => 200,
        ]);
})->with([
    'global' => [
        'options' => ['manual' => false],
        'withTrace' => false,
    ],
    'manual' => [
        'options' => ['manual' => true],
        'withTrace' => true,
    ],
    'both' => [
        'options' => ['manual' => false],
        'withTrace' => true,
    ],
]);

it('set span status to error on 4xx and 5xx status code', function () {
    registerInstrumentation(HttpClientInstrumentation::class);

    Http::fake([
        '*' => Http::response('', 500, ['Content-Length' => 0]),
    ]);

    withRootSpan(function () {
        Http::get(Server::$url);
    });

    $httpSpan = getRecordedSpans()->first();

    expect($httpSpan)
        ->getKind()->toBe(SpanKind::KIND_CLIENT)
        ->getName()->toBe('GET')
        ->getStatus()->getCode()->toBe(StatusCode::STATUS_ERROR)
        ->getAttributes()->toMatchArray([
            'http.response.status_code' => 500,
        ]);
});

it('trace allowed request headers', function () {
    registerInstrumentation(HttpClientInstrumentation::class, [
        'allowed_headers' => [
            'x-foo',
        ],
    ]);

    Http::fake([
        '*' => Http::response('', 200, ['Content-Length' => 0]),
    ]);

    withRootSpan(function () {
        Http::withHeaders([
            'x-foo' => 'bar',
            'x-bar' => 'baz',
        ])->get(Server::$url);
    });

    $span = getRecordedSpans()->first();

    expect($span->getAttributes())
        ->toMatchArray([
            'http.request.header.x-foo' => ['bar'],
        ])
        ->not->toHaveKey('http.request.header.x-bar');
});

it('trace allowed response headers', function () {
    registerInstrumentation(HttpClientInstrumentation::class, [
        'allowed_headers' => [
            'content-type',
        ],
    ]);

    Http::fake([
        '*' => Http::response('', 200, ['Content-Length' => 0, 'Content-Type' => 'text/html; charset=UTF-8']),
    ]);

    withRootSpan(function () {
        Http::get(Server::$url);
    });

    $span = getRecordedSpans()->first();

    expect($span->getAttributes())
        ->toMatchArray([
            'http.response.header.content-type' => ['text/html; charset=UTF-8'],
        ])
        ->not->toHaveKey('http.response.header.date');
});

it('trace sensitive headers with hidden value', function () {
    registerInstrumentation(HttpClientInstrumentation::class, [
        'allowed_headers' => [
            'x-foo',
        ],
        'sensitive_headers' => [
            'x-foo',
        ],
    ]);

    Http::fake([
        '*' => Http::response('', 200, ['Content-Length' => 0]),
    ]);

    withRootSpan(function () {
        Http::withHeaders(['x-foo' => 'bar'])->get(Server::$url);
    });

    $span = getRecordedSpans()->first();

    expect($span->getAttributes())
        ->toMatchArray([
            'http.request.header.x-foo' => ['*****'],
        ]);
});

it('mark some headers as sensitive by default', function () {
    registerInstrumentation(HttpClientInstrumentation::class, [
        'allowed_headers' => [
            'authorization',
            'cookie',
            'set-cookie',
        ],
    ]);

    Http::fake([
        '*' => Http::response('', 200, ['Content-Length' => 0, 'Set-Cookie' => 'cookie']),
    ]);

    withRootSpan(function () {
        Http::withHeaders([
            'authorization' => 'Bearer token',
            'cookie' => 'cookie',
        ])->get(Server::$url);
    });

    $span = getRecordedSpans()->first();

    expect($span->getAttributes())
        ->toMatchArray([
            'http.request.header.authorization' => ['*****'],
            'http.request.header.cookie' => ['*****'],
            'http.response.header.set-cookie' => ['*****'],
        ]);
});

it('can resolve route name', function () {
    registerInstrumentation(HttpClientInstrumentation::class);

    HttpClientInstrumentation::setRouteNameResolver(function (\Psr\Http\Message\RequestInterface $request) {
        return match (true) {
            str_starts_with($request->getUri()->getPath(), '/products/') => '/products/{id}',
            default => null,
        };
    });

    Http::fake([
        '*' => Http::response('', 200, ['Content-Length' => 0]),
    ]);

    withRootSpan(function () {
        Http::get(Server::$url.'products/123');
    });

    $span = getRecordedSpans()->first();

    expect($span)
        ->getName()->toBe('GET /products/{id}')
        ->getAttributes()->toMatchArray([
            'url.template' => '/products/{id}',
        ]);
});
