<?php

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\ScoutInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Tests\Support\SearchableProduct;
use Laravel\Scout\EngineManager;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Metrics\DbMetrics;

beforeEach(function () {
    skipWithoutOtelExtension();
});

test('trace scout search operation', function () {
    registerInstrumentation(ScoutInstrumentation::class);

    SearchableProduct::query()->create(['name' => 'Lorem ipsum']);

    withRootSpan(function () {
        return SearchableProduct::search('lorem')->get();
    });

    $spans = getRecordedSpans();

    $searchSpan = $spans->first(fn (SpanDataInterface $span) => $span->getName() === 'search products');

    expect($searchSpan)
        ->getName()->toBe('search products')
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

    SearchableProduct::query()->create(['name' => 'Lorem ipsum']);

    withRootSpan(function () {
        return SearchableProduct::search('lorem')->paginate();
    });

    $spans = getRecordedSpans();

    $searchSpan = $spans->first(fn (SpanDataInterface $span) => $span->getName() === 'search products');

    expect($searchSpan)
        ->getName()->toBe('search products')
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

    $product = SearchableProduct::query()->create(['name' => 'Lorem ipsum']);

    withRootSpan(function () use ($product) {
        $product->searchableSync();
    });

    $spans = getRecordedSpans();

    $searchSpan = $spans->first(fn (SpanDataInterface $span) => $span->getName() === 'search_update products');

    expect($searchSpan)
        ->getName()->toBe('search_update products')
        ->getKind()->toBe(SpanKind::KIND_CLIENT)
        ->getAttributes()
        ->toMatchArray([
            'db.system.name' => 'scout_collection',
            'db.namespace' => 'products',
            'db.operation.name' => 'search_update',
            'db.operation.batch.size' => 1,
            'db.operation.batch.ids' => '1',
        ]);
});

test('trace scout delete operation', function () {
    registerInstrumentation(ScoutInstrumentation::class);

    $product = SearchableProduct::query()->create(['name' => 'Lorem ipsum']);

    withRootSpan(function () use ($product) {
        $product->delete();
    });

    $spans = getRecordedSpans();

    $searchSpan = $spans->first(fn (SpanDataInterface $span) => $span->getName() === 'search_delete products');

    expect($searchSpan)
        ->getName()->toBe('search_delete products')
        ->getKind()->toBe(SpanKind::KIND_CLIENT)
        ->getAttributes()
        ->toMatchArray([
            'db.system.name' => 'scout_collection',
            'db.namespace' => 'products',
            'db.operation.name' => 'search_delete',
            'db.operation.batch.size' => 1,
            'db.operation.batch.ids' => '1',
        ]);
});

test('scout instrumentation skips spans when trace not started', function () {
    registerInstrumentation(ScoutInstrumentation::class);

    SearchableProduct::search('lorem')->first();

    expect(getRecordedSpans())->toBeEmpty();
});

test('records scout operation duration metric', function () {
    registerInstrumentation(ScoutInstrumentation::class);

    SearchableProduct::query()->create(['name' => 'Lorem ipsum']);

    withRootSpan(function () {
        return SearchableProduct::search('lorem')->get();
    });

    $metric = getRecordedMetrics()->where('name', DbMetrics::DB_CLIENT_OPERATION_DURATION)->sole();

    expect($metric)
        ->toBeInstanceOf(Metric::class)
        ->unit->toBe('s')
        ->data->toBeInstanceOf(Histogram::class);

    /** @var HistogramDataPoint $dataPoint */
    $dataPoint = $metric->data->dataPoints[0];

    expect($dataPoint->attributes)
        ->toMatchArray([
            DbAttributes::DB_SYSTEM_NAME => 'scout_collection',
            DbAttributes::DB_NAMESPACE => 'products',
            DbAttributes::DB_OPERATION_NAME => 'search',
        ]);
});

test('records failed scout operations', function () {
    registerInstrumentation(ScoutInstrumentation::class);

    Schema::drop('products');

    withRootSpan(fn () => rescue(fn () => SearchableProduct::search('lorem')->get(), report: false));

    $searchSpan = getRecordedSpans()->first(fn (SpanDataInterface $span) => $span->getName() === 'search products');

    expect($searchSpan)
        ->getStatus()->getCode()->toBe(StatusCode::STATUS_ERROR)
        ->getAttributes()->toArray()->toMatchArray([
            'error.type' => QueryException::class,
        ]);

    /** @var HistogramDataPoint $dataPoint */
    $dataPoint = getRecordedMetrics()->where('name', DbMetrics::DB_CLIENT_OPERATION_DURATION)->sole()->data->dataPoints[0];

    expect($dataPoint->attributes->get(ErrorAttributes::ERROR_TYPE))->toBe(QueryException::class);
});

test('scout span name has no trailing space without target', function () {
    registerInstrumentation(ScoutInstrumentation::class);

    withRootSpan(fn () => app(EngineManager::class)->engine()->update(new Collection));

    expect(getRecordedSpans()->map(fn (SpanDataInterface $span) => $span->getName()))
        ->toContain('search_update');
});
