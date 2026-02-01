<?php

use Keepsuit\LaravelOpenTelemetry\Instrumentation\ScoutInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Tests\Support\Product;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\SpanDataInterface;

test('trace scout search operation', function () {
    registerInstrumentation(ScoutInstrumentation::class);

    Product::query()->create(['name' => 'Lorem ipsum']);

    withRootSpan(function () {
        return Product::search('lorem')->get();
    });

    $spans = getRecordedSpans();

    $searchSpan = $spans->first(fn (SpanDataInterface $span) => $span->getName() === 'search');

    expect($searchSpan)
        ->getName()->toBe('search')
        ->getKind()->toBe(SpanKind::KIND_CLIENT)
        ->getAttributes()
        ->toMatchArray([
            'db.system.name' => 'scout_collection',
            'db.namespace' => 'products',
            'db.operation.name' => 'search',
            'db.query.text' => 'lorem',
        ]);
});

test('trace scout paginate operation', function () {
    registerInstrumentation(ScoutInstrumentation::class);

    Product::query()->create(['name' => 'Lorem ipsum']);

    withRootSpan(function () {
        return Product::search('lorem')->paginate();
    });

    $spans = getRecordedSpans();

    $searchSpan = $spans->first(fn (SpanDataInterface $span) => $span->getName() === 'search');

    expect($searchSpan)
        ->getName()->toBe('search')
        ->getKind()->toBe(SpanKind::KIND_CLIENT)
        ->getAttributes()
        ->toMatchArray([
            'db.system.name' => 'scout_collection',
            'db.namespace' => 'products',
            'db.operation.name' => 'search',
            'db.query.text' => 'lorem (page: 1, per_page: 15)',
        ]);
});

test('trace scout update operation', function () {
    registerInstrumentation(ScoutInstrumentation::class);

    withRootSpan(function () {
        Product::query()->create(['name' => 'Lorem ipsum']);
    });

    $spans = getRecordedSpans();

    $searchSpan = $spans->first(fn (SpanDataInterface $span) => $span->getName() === 'search_update');

    expect($searchSpan)
        ->getName()->toBe('search_update')
        ->getKind()->toBe(SpanKind::KIND_CLIENT)
        ->getAttributes()
        ->toMatchArray([
            'db.system.name' => 'scout_collection',
            'db.namespace' => 'products',
            'db.operation.name' => 'search_update',
            'db.operation.batch.size' => 1,
        ]);
});

test('trace scout delete operation', function () {
    registerInstrumentation(ScoutInstrumentation::class);

    $product = Product::query()->create(['name' => 'Lorem ipsum']);

    withRootSpan(function () use ($product) {
        $product->delete();
    });

    $spans = getRecordedSpans();

    $searchSpan = $spans->first(fn (SpanDataInterface $span) => $span->getName() === 'search_delete');

    expect($searchSpan)
        ->getName()->toBe('search_delete')
        ->getKind()->toBe(SpanKind::KIND_CLIENT)
        ->getAttributes()
        ->toMatchArray([
            'db.system.name' => 'scout_collection',
            'db.namespace' => 'products',
            'db.operation.name' => 'search_delete',
            'db.operation.batch.size' => 1,
        ]);
});

test('scout instrumentation skips spans when trace not started', function () {
    registerInstrumentation(ScoutInstrumentation::class);

    Product::search('lorem')->first();

    expect(getRecordedSpans())->toBeEmpty();
});
