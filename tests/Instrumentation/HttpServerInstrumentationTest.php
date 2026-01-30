<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\HttpServerInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Tests\Support\Product;
use Keepsuit\LaravelOpenTelemetry\Tests\Support\TestException;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\Metrics\HttpMetrics;

use function Pest\Laravel\withoutExceptionHandling;

uses(RefreshDatabase::class);

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::any('test-ok', fn () => Tracer::traceId());
        Route::any('test-exception', fn () => throw TestException::create());
        Route::any('test-nested-exception', function () {
            $span = Tracer::newSpan('nested')->start();
            $span->activate();

            throw TestException::create();
        });
        Route::any('test-log', function () {
            Log::info('Test log message');

            return Tracer::traceId();
        });
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
        ->getName()->toBe('GET /test-ok')
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
            'network.protocol.name' => 'http',
            'network.protocol.version' => '1.1',
            'network.peer.address' => '127.0.0.1',
            'http.response.status_code' => 200,
            'http.response.body.size' => 32,
        ]);

    expect(Log::sharedContext())->toMatchArray([
        'trace_id' => $traceId,
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
        ->getName()->toBe('GET /products/{product}')
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
            'network.protocol.name' => 'http',
            'network.protocol.version' => '1.1',
            'network.peer.address' => '127.0.0.1',
            'http.response.status_code' => 200,
            'http.response.body.size' => 32,
            'product' => 1,
        ]);
});

it('can record route exception', function () {
    registerInstrumentation(HttpServerInstrumentation::class);

    $response = $this->get('test-exception');

    $response->assertServerError();

    $span = getRecordedSpans()->last();

    expect($span)
        ->getName()->toBe('GET /test-exception')
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
            'network.protocol.name' => 'http',
            'network.protocol.version' => '1.1',
            'network.peer.address' => '127.0.0.1',
            'http.response.status_code' => 500,
        ])
        ->getEvents()->not->toBeEmpty();

    expect(collect($span->getEvents())->last())
        ->toBeInstanceOf(OpenTelemetry\SDK\Trace\Event::class)
        ->getAttributes()->get('exception.type')->toBe(TestException::class)
        ->getAttributes()->get('exception.message')->toBe('Exception thrown!');
});

it('can record a route exception in a nested span', function () {
    registerInstrumentation(HttpServerInstrumentation::class);

    $response = $this->get('test-nested-exception');

    $response->assertServerError();

    $spans = getRecordedSpans()->reverse();
    $nestedSpan = $spans->get(0);
    $routeSpan = $spans->get(1);

    expect($nestedSpan)
        ->getName()->toBe('nested')
        ->getKind()->toBe(SpanKind::KIND_INTERNAL)
        ->getStatus()->getCode()->toBe(StatusCode::STATUS_ERROR)
        ->getEvents()->not->toBeEmpty();

    expect(collect($nestedSpan->getEvents())->last())
        ->toBeInstanceOf(OpenTelemetry\SDK\Trace\Event::class)
        ->getAttributes()->get('exception.type')->toBe(TestException::class)
        ->getAttributes()->get('exception.message')->toBe('Exception thrown!');

    expect($routeSpan)
        ->getName()->toBe('GET /test-nested-exception')
        ->getKind()->toBe(SpanKind::KIND_SERVER)
        ->getStatus()->getCode()->toBe(StatusCode::STATUS_ERROR)
        ->getEvents()->toBeEmpty();
});

it('skips route exception when it is not reportable', function () {
    registerInstrumentation(HttpServerInstrumentation::class);
    app(Illuminate\Contracts\Debug\ExceptionHandler::class)->ignore(TestException::class);

    $this
        ->get('test-exception')
        ->assertServerError();

    $lastSpan = getRecordedSpans()->last();

    expect($lastSpan)->getStatus()->getCode()->toBe(StatusCode::STATUS_ERROR);
    expect($lastSpan->getEvents())->toBeEmpty();
});

it('set generic span name when route has parameters', function () {
    registerInstrumentation(HttpServerInstrumentation::class);

    $response = $this->get('test/user1');

    $response->assertOk();

    expect($response->content())
        ->not->toBeEmpty();

    $spans = getRecordedSpans();

    expect($spans->last())
        ->getName()->toBe('GET /test/{parameter}')
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
        ->getName()->toBe('GET /test-ok')
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
        ->toHaveKey('http.response.header.content-type')
        ->not->toHaveKey('http.response.header.date');

    expect(strtolower($span->getAttributes()->get('http.response.header.content-type')[0]))
        ->toBe('text/html; charset=utf-8');
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

it('skip tracing for excluded HTTP methods', function () {
    registerInstrumentation(HttpServerInstrumentation::class, [
        'excluded_methods' => [
            'HEAD',
            'OPTIONS',
        ],
    ]);

    // Test HEAD request
    $response = $this->head('test-ok');
    $response->assertOk();

    $spans = getRecordedSpans();
    $serverSpan = $spans->firstWhere(fn (\OpenTelemetry\SDK\Trace\ImmutableSpan $span) => $span->getKind() === SpanKind::KIND_SERVER);

    expect($serverSpan)->toBeNull();

    resetStorage();

    // Test OPTIONS request
    $response = $this->options('test-ok');
    $response->assertOk();

    $spans = getRecordedSpans();
    $serverSpan = $spans->firstWhere(fn (\OpenTelemetry\SDK\Trace\ImmutableSpan $span) => $span->getKind() === SpanKind::KIND_SERVER);

    expect($serverSpan)->toBeNull();
});

it('trace requests with non-excluded HTTP methods', function () {
    registerInstrumentation(HttpServerInstrumentation::class, [
        'excluded_methods' => [
            'HEAD',
            'OPTIONS',
        ],
    ]);

    // Test GET request (should be traced)
    $response = $this->get('test-ok');
    $response->assertOk();

    $spans = getRecordedSpans();
    $serverSpan = $spans->firstWhere(fn (\OpenTelemetry\SDK\Trace\ImmutableSpan $span) => $span->getKind() === SpanKind::KIND_SERVER);

    expect($serverSpan)
        ->not->toBeNull()
        ->getName()->toBe('GET /test-ok');

    resetStorage();

    // Test POST request (should be traced)
    $response = $this->post('test-ok');
    $response->assertOk();

    $spans = getRecordedSpans();
    $serverSpan = $spans->firstWhere(fn (\OpenTelemetry\SDK\Trace\ImmutableSpan $span) => $span->getKind() === SpanKind::KIND_SERVER);

    expect($serverSpan)
        ->not->toBeNull()
        ->getName()->toBe('POST /test-ok');
});

it('handle excluded methods case-insensitively', function () {
    registerInstrumentation(HttpServerInstrumentation::class, [
        'excluded_methods' => [
            'head',  // lowercase
            'OpTiOnS',  // mixed case
        ],
    ]);

    // Test HEAD request
    $response = $this->head('test-ok');
    $response->assertOk();

    $spans = getRecordedSpans();
    $serverSpan = $spans->firstWhere(fn (\OpenTelemetry\SDK\Trace\ImmutableSpan $span) => $span->getKind() === SpanKind::KIND_SERVER);

    expect($serverSpan)->toBeNull();

    resetStorage();

    // Test OPTIONS request
    $response = $this->options('test-ok');
    $response->assertOk();

    $spans = getRecordedSpans();
    $serverSpan = $spans->firstWhere(fn (\OpenTelemetry\SDK\Trace\ImmutableSpan $span) => $span->getKind() === SpanKind::KIND_SERVER);

    expect($serverSpan)->toBeNull();
});

it('redact sensitive query string parameters', function () {
    registerInstrumentation(HttpServerInstrumentation::class, [
        'sensitive_query_parameters' => [
            'token',
        ],
    ]);

    $response = $this->get('test-ok?token=secret&param=value');

    $response->assertOk();

    $span = getRecordedSpans()->first();

    expect($span->getAttributes())->toMatchArray([
        'url.full' => 'http://localhost/test-ok?param=value&token=REDACTED',
        'url.query' => 'param=value&token=REDACTED',
    ]);
});

it('record forwarded ip from trusted proxies', function () {
    registerInstrumentation(HttpServerInstrumentation::class);

    \Illuminate\Http\Middleware\TrustProxies::at('*');

    $response = $this->get('test-ok', [
        'X-Forwarded-For' => '93.125.25.10',
    ]);

    $response->assertOk();

    $span = getRecordedSpans()->last();

    expect($span->getAttributes())
        ->toMatchArray([
            \OpenTelemetry\SemConv\Attributes\ClientAttributes::CLIENT_ADDRESS => '93.125.25.10',
            \OpenTelemetry\SemConv\Attributes\NetworkAttributes::NETWORK_PEER_ADDRESS => '127.0.0.1',
        ]);
});

it('adds user.id when enabled and user is authenticated', function () {
    config(['opentelemetry.user_context' => true]);

    registerInstrumentation(HttpServerInstrumentation::class);

    $user = new \Illuminate\Foundation\Auth\User;
    $user->id = 123;

    $response = $this->actingAs($user)->get('test-ok');
    $response->assertOk();

    $span = getRecordedSpans()->last();

    expect($span->getAttributes())
        ->toMatchArray([
            'user.id' => 123,
        ]);
});

it('does not add user.id when disabled', function () {
    config(['opentelemetry.user_context' => false]);

    registerInstrumentation(HttpServerInstrumentation::class);

    $user = new \Illuminate\Foundation\Auth\User;
    $user->id = 123;

    $response = $this->actingAs($user)->get('test-ok');
    $response->assertOk();

    $span = getRecordedSpans()->last();

    expect($span->getAttributes())->not->toHaveKey('user.id');
});

it('collects extra user attributes with custom user resolver', function () {
    config(['opentelemetry.user_context' => true]);

    registerInstrumentation(HttpServerInstrumentation::class);

    \Keepsuit\LaravelOpenTelemetry\Facades\OpenTelemetry::user(fn (\Illuminate\Contracts\Auth\Authenticatable $user) => ['user.email' => $user->email]);

    $user = new \Illuminate\Foundation\Auth\User;
    $user->id = 123;
    $user->email = 'test@example.com';

    $response = $this->actingAs($user)->get('test-ok');
    $response->assertOk();

    $span = getRecordedSpans()->last();

    expect($span->getAttributes())
        ->toHaveKey('user.email')
        ->not->toHaveKey('user.id')
        ->toMatchArray([
            'user.email' => 'test@example.com',
        ]);
});

it('adds user context to logs when authenticated', function () {
    config(['opentelemetry.user_context' => true]);

    registerInstrumentation(HttpServerInstrumentation::class);

    $user = new \Illuminate\Foundation\Auth\User;
    $user->id = 123;

    $response = $this->actingAs($user)->get('test-log');
    $response->assertOk();

    $span = getRecordedSpans()->last();
    $log = getRecordedLogs()->last();

    expect($log->getSpanContext()->getTraceId())->toBe($span->getTraceId());

    expect($span->getAttributes())->toMatchArray([
        'user.id' => 123,
    ]);

    expect($log->getAttributes())->toMatchArray([
        'user.id' => 123,
    ]);
});

it('can record http server request duration metric', function () {
    registerInstrumentation(HttpServerInstrumentation::class);

    $this->get('test-ok')->assertOk();

    $metric = getRecordedMetrics()->firstWhere('name', HttpMetrics::HTTP_SERVER_REQUEST_DURATION);

    expect($metric)->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class)
        ->name->toBe(HttpMetrics::HTTP_SERVER_REQUEST_DURATION)
        ->unit->toBe('s')
        ->data->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Histogram::class);

    /** @var \OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint $dataPoint */
    $dataPoint = $metric->data->dataPoints[0];

    expect($dataPoint->attributes)
        ->toMatchArray([
            \OpenTelemetry\SemConv\Attributes\UrlAttributes::URL_SCHEME => 'http',
            \OpenTelemetry\SemConv\Attributes\HttpAttributes::HTTP_REQUEST_METHOD => 'GET',
            \OpenTelemetry\SemConv\Attributes\HttpAttributes::HTTP_RESPONSE_STATUS_CODE => 200,
            \OpenTelemetry\SemConv\Attributes\HttpAttributes::HTTP_ROUTE => '/test-ok',
            \OpenTelemetry\SemConv\Attributes\NetworkAttributes::NETWORK_PROTOCOL_NAME => 'http',
            \OpenTelemetry\SemConv\Attributes\NetworkAttributes::NETWORK_PROTOCOL_VERSION => '1.1',
            \OpenTelemetry\SemConv\Attributes\ServerAttributes::SERVER_ADDRESS => 'localhost',
            \OpenTelemetry\SemConv\Attributes\ServerAttributes::SERVER_PORT => '80',
        ]);
});

it('records metric even when exception occurs', function () {
    registerInstrumentation(HttpServerInstrumentation::class);

    $this->get('test-exception');

    $metric = getRecordedMetrics()->firstWhere('name', HttpMetrics::HTTP_SERVER_REQUEST_DURATION);

    expect($metric)->not->toBeNull();

    /** @var \OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint $dataPoint */
    $dataPoint = $metric->data->dataPoints[0];

    expect($dataPoint)
        ->not->toBeNull()
        ->attributes->toMatchArray([
            \OpenTelemetry\SemConv\Attributes\ErrorAttributes::ERROR_TYPE => 500,
            \OpenTelemetry\SemConv\Attributes\HttpAttributes::HTTP_RESPONSE_STATUS_CODE => 500,
        ]);
});
