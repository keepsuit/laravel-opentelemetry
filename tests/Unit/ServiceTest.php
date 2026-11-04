<?php

use Keepsuit\LaravelOpenTelemetry\Support\ResourceAttributesParser;
use Keepsuit\LaravelOpenTelemetry\Support\ResourceBuilder;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\ContextKeys;
use OpenTelemetry\Context\Context;

test('service resource attributes', function () {
    $resource = ResourceBuilder::build();

    expect($resource->getAttributes())
        ->get('service.name')->toBe('laravel-test-app')
        ->get('service.instance.id')->toBeNull();
});

test('custom instance id', function () {
    config()->set('opentelemetry.service_instance_id', 'custom-id-1234');

    $resource = ResourceBuilder::build();

    expect($resource->getAttributes())
        ->get('service.instance.id')->toBe('custom-id-1234');
});

test('parse resource attributes from env', function () {
    expect(ResourceAttributesParser::parse(''))->toBe([]);

    expect(ResourceAttributesParser::parse('key1=value1'))->toBe(['key1' => 'value1']);

    expect(ResourceAttributesParser::parse('key1=value1,key2=value2'))->toBe(['key1' => 'value1', 'key2' => 'value2']);
});

test('set resource attributes from config', function () {
    config()->set('opentelemetry.resource_attributes', [
        'environment' => 'production',
        'region' => 'us-west-2',
    ]);

    $resource = ResourceBuilder::build();

    expect($resource->getAttributes())
        ->get('environment')->toBe('production')
        ->get('region')->toBe('us-west-2');
});

test('a repeated boot does not keep the previous sdk attached to the context', function () {
    $previous = Globals::tracerProvider();

    $this->refreshApplication();
    expect(Globals::tracerProvider())->not->toBe($previous);

    // Without the current boot's scope, nothing from the previous boot may remain attached.
    Context::storage()->scope()?->detach();
    expect(Context::getCurrent()->get(ContextKeys::tracerProvider()))->toBeNull();

    $this->refreshApplication();
});
