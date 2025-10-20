<?php

use Keepsuit\LaravelOpenTelemetry\Support\ResourceBuilder;

test('service resource attributes', function () {
    $resource = ResourceBuilder::build();

    expect($resource->getAttributes())
        ->get('service.name')->toBe('laravel-app')
        ->get('service.instance.id')->toBeNull();
});

test('custom instance id', function () {
    config()->set('opentelemetry.service_instance_id', 'custom-id-1234');

    $resource = ResourceBuilder::build();

    expect($resource->getAttributes())
        ->get('service.instance.id')->toBe('custom-id-1234');
});
