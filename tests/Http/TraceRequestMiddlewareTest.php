<?php

use Illuminate\Support\Facades\Route;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Http\Middleware\TraceRequest;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

beforeEach(function () {
    Route::any('test-ok', fn () => Tracer::traceId())->middleware(TraceRequest::class);
    Route::any('test-exception', fn () => throw new Exception('test exception'))->middleware(TraceRequest::class);
});

it('can trace a request', function () {
    $response = $this->get('test-ok');

    $response->assertOk();

    expect($response->content())
        ->not->toBeEmpty();

    $traceId = $response->content();

    $spans = getRecordedSpans();

    expect($spans)
        ->toHaveCount(1);

    expect($spans[0])
        ->getName()->toBe('/test-ok')
        ->getKind()->toBe(SpanKind::KIND_SERVER)
        ->getStatus()->getCode()->toBe(StatusCode::STATUS_OK)
        ->getTraceId()->toBe($traceId)
        ->getAttributes()->toMatchArray([
            'http.method' => 'GET',
            'http.url' => 'http://localhost/test-ok',
            'http.target' => '/test-ok',
            'http.route' => '/test-ok',
            'http.host' => 'localhost',
            'http.scheme' => 'http',
            'http.user_agent' => 'Symfony',
            'http.status_code' => 200,
            'http.response_content_length' => 32,
        ]);
});

it('can record route exception', function () {
    $response = $this->get('test-exception');

    $response->assertServerError();

    $spans = getRecordedSpans();

    expect($spans)
        ->toHaveCount(1);

    expect($spans[0])
        ->getName()->toBe('/test-exception')
        ->getKind()->toBe(SpanKind::KIND_SERVER)
        ->getStatus()->getCode()->toBe(StatusCode::STATUS_ERROR)
        ->getAttributes()->toMatchArray([
            'http.method' => 'GET',
            'http.url' => 'http://localhost/test-exception',
            'http.target' => '/test-exception',
            'http.route' => '/test-exception',
            'http.host' => 'localhost',
            'http.scheme' => 'http',
            'http.user_agent' => 'Symfony',
            'http.status_code' => 500,
        ]);
});

it('continue trace', function () {
    $response = $this->get('test-ok', [
        'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
    ]);

    $response->assertOk();

    $spans = getRecordedSpans();

    expect($spans)
        ->toHaveCount(1);

    expect($spans[0])
        ->getName()->toBe('/test-ok')
        ->getKind()->toBe(SpanKind::KIND_SERVER)
        ->getStatus()->getCode()->toBe(StatusCode::STATUS_OK)
        ->getTraceId()->toBe('0af7651916cd43dd8448eb211c80319c')
        ->getParentSpanId()->toBe('b7ad6b7169203331');
});
