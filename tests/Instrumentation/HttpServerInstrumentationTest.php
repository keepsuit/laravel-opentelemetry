<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\HttpServerInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Tests\Support\Product;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

use function Pest\Laravel\withoutExceptionHandling;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::any('test-ok', fn () => Tracer::traceId())->middleware(['web']);
        Route::any('test-exception', fn () => throw new Exception('test exception'));
        Route::any('test/{parameter}', fn () => Tracer::traceId());
        Route::get('products/{product}', function (Product $product) {
            Tracer::activeSpan()->setAttribute('product', $product->id);

            return Tracer::traceId();
        });
    });
});

it('can trace a request', function () {
    registerInstrumentation(HttpServerInstrumentation::class);

    $response = $this->get('test-ok');

    $response->assertOk();

    expect($response->content())
        ->not->toBeEmpty();

    $traceId = $response->content();

    $spans = getRecordedSpans();

    expect($spans->last())
        ->getName()->toBe('/test-ok')
        ->getKind()->toBe(SpanKind::KIND_SERVER)
        ->getStatus()->getCode()->toBe(StatusCode::STATUS_OK)
        ->getTraceId()->toBe($traceId)
        ->getAttributes()->toMatchArray([
            'url.full' => 'http://localhost/test-ok',
            'url.path' => '/test-ok',
            'url.scheme' => 'http',
            'http.route' => '/test-ok',
            'http.request.method' => 'GET',
            'server.address' => 'localhost',
            'server.port' => 80,
            'user_agent.original' => 'Symfony',
            'network.protocol.version' => 'HTTP/1.1',
            'network.peer.address' => '127.0.0.1',
            'http.response.status_code' => 200,
            'http.response.body.size' => 32,
        ]);

    expect(Log::sharedContext())->toMatchArray([
        'traceid' => $traceId,
    ]);
});

it('can trace a request with route model binding', function () {
    registerInstrumentation(HttpServerInstrumentation::class);

    $product = Product::create(['name' => 'test']);
    $response = withoutExceptionHandling()->get('products/'.$product->id);

    $response->assertOk();

    expect($response->content())
        ->not->toBeEmpty();

    $traceId = $response->content();

    $span = getRecordedSpans()->last();

    expect($span)
        ->getName()->toBe('/products/{product}')
        ->getKind()->toBe(SpanKind::KIND_SERVER)
        ->getStatus()->getCode()->toBe(StatusCode::STATUS_OK)
        ->getTraceId()->toBe($traceId)
        ->getAttributes()->toMatchArray([
            'url.full' => 'http://localhost/products/1',
            'url.path' => '/products/1',
            'url.scheme' => 'http',
            'http.route' => '/products/{product}',
            'http.request.method' => 'GET',
            'server.address' => 'localhost',
            'server.port' => 80,
            'user_agent.original' => 'Symfony',
            'network.protocol.version' => 'HTTP/1.1',
            'network.peer.address' => '127.0.0.1',
            'http.response.status_code' => 200,
            'http.response.body.size' => 32,
            'product' => 1,
        ]);

    expect(Log::sharedContext())->toMatchArray([
        'traceid' => $traceId,
    ]);
});

it('can record route exception', function () {
    registerInstrumentation(HttpServerInstrumentation::class);

    $response = $this->get('test-exception');

    $response->assertServerError();

    $spans = getRecordedSpans();

    expect($spans->last())
        ->getName()->toBe('/test-exception')
        ->getKind()->toBe(SpanKind::KIND_SERVER)
        ->getStatus()->getCode()->toBe(StatusCode::STATUS_ERROR)
        ->getAttributes()->toMatchArray([
            'url.full' => 'http://localhost/test-exception',
            'url.path' => '/test-exception',
            'url.scheme' => 'http',
            'http.route' => '/test-exception',
            'http.request.method' => 'GET',
            'server.address' => 'localhost',
            'server.port' => 80,
            'user_agent.original' => 'Symfony',
            'network.protocol.version' => 'HTTP/1.1',
            'network.peer.address' => '127.0.0.1',
            'http.response.status_code' => 500,
        ]);
});

it('set generic span name when route has parameters', function () {
    registerInstrumentation(HttpServerInstrumentation::class);

    $response = $this->get('test/user1');

    $response->assertOk();

    expect($response->content())
        ->not->toBeEmpty();

    $spans = getRecordedSpans();

    expect($spans->last())
        ->getName()->toBe('/test/{parameter}')
        ->getKind()->toBe(SpanKind::KIND_SERVER)
        ->getStatus()->getCode()->toBe(StatusCode::STATUS_OK)
        ->getAttributes()->toMatchArray([
            'url.full' => 'http://localhost/test/user1',
            'url.path' => '/test/user1',
            'http.route' => '/test/{parameter}',
            'http.request.method' => 'GET',
        ]);
});

it('continue trace', function () {
    registerInstrumentation(HttpServerInstrumentation::class);

    $response = $this->get('test-ok', [
        'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
    ]);

    $response->assertOk();

    $spans = getRecordedSpans();

    expect($spans->last())
        ->getName()->toBe('/test-ok')
        ->getKind()->toBe(SpanKind::KIND_SERVER)
        ->getStatus()->getCode()->toBe(StatusCode::STATUS_OK)
        ->getTraceId()->toBe('0af7651916cd43dd8448eb211c80319c')
        ->getParentSpanId()->toBe('b7ad6b7169203331');
});

it('skip tracing for excluded paths', function () {
    registerInstrumentation(HttpServerInstrumentation::class, [
        'excluded_paths' => [
            'test-ok',
        ],
    ]);

    $response = $this->get('test-ok');

    $response->assertOk();

    $spans = getRecordedSpans();

    $serverSpan = $spans->firstWhere(fn (\OpenTelemetry\SDK\Trace\ImmutableSpan $span) => $span->getKind() === SpanKind::KIND_SERVER);

    expect($serverSpan)
        ->toBeNull();
});

it('trace allowed request headers', function () {
    registerInstrumentation(HttpServerInstrumentation::class, [
        'allowed_headers' => [
            'x-foo',
        ],
    ]);

    $response = $this->get('test-ok', [
        'x-foo' => 'bar',
        'x-bar' => 'baz',
    ]);

    $response->assertOk();

    expect($response->content())
        ->not->toBeEmpty();

    $span = getRecordedSpans()->last();

    expect($span->getAttributes())
        ->toMatchArray([
            'http.request.header.x-foo' => ['bar'],
        ])
        ->not->toHaveKey('http.request.header.x-bar');
});

it('trace allowed response headers', function () {
    registerInstrumentation(HttpServerInstrumentation::class, [
        'allowed_headers' => [
            'content-type',
        ],
    ]);

    $response = $this->get('test-ok');

    $response->assertOk();

    expect($response->content())
        ->not->toBeEmpty();

    $span = getRecordedSpans()->last();

    expect($span->getAttributes())
        ->toMatchArray([
            'http.response.header.content-type' => ['text/html; charset=UTF-8'],
        ])
        ->not->toHaveKey('http.response.header.date');
});

it('trace sensitive headers with hidden value', function () {
    registerInstrumentation(HttpServerInstrumentation::class, [
        'allowed_headers' => [
            'x-foo',
        ],
        'sensitive_headers' => [
            'x-foo',
        ],
    ]);

    $response = $this->get('test-ok', [
        'x-foo' => 'bar',
    ]);

    $response->assertOk();

    expect($response->content())
        ->not->toBeEmpty();

    $span = getRecordedSpans()->last();

    expect($span->getAttributes())
        ->toMatchArray([
            'http.request.header.x-foo' => ['*****'],
        ]);
});

it('mark some headers as sensitive by default', function () {
    registerInstrumentation(HttpServerInstrumentation::class, [
        'allowed_headers' => [
            'authorization',
            'cookie',
            'set-cookie',
        ],
    ]);

    $response = $this->get('test-ok', [
        'authorization' => 'Bearer token',
        'cookie' => 'cookie',
    ]);

    $response->assertOk();

    expect($response->content())
        ->not->toBeEmpty();

    $span = getRecordedSpans()->last();

    expect($span->getAttributes())
        ->toMatchArray([
            'http.request.header.authorization' => ['*****'],
            'http.request.header.cookie' => ['*****'],
            'http.response.header.set-cookie' => ['*****'],
        ]);
});
