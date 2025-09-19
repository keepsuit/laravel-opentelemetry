<?php

use Keepsuit\LaravelOpenTelemetry\LaravelOpenTelemetryServiceProvider;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;

test('service provider can be instantiated', function () {
    $provider = new LaravelOpenTelemetryServiceProvider($this->app);
    expect($provider)->toBeInstanceOf(LaravelOpenTelemetryServiceProvider::class);
});

test('metrics exporter is bound to container', function () {
    expect(app(MetricExporterInterface::class))
        ->toBeInstanceOf(MetricExporterInterface::class);
});

test('temporality configuration is readable', function () {
    // Test default configuration
    expect(config('opentelemetry.metrics.temporality'))->toBe('Delta');
    
    // Test configuration can be changed
    config(['opentelemetry.metrics.temporality' => 'Cumulative']);
    expect(config('opentelemetry.metrics.temporality'))->toBe('Cumulative');
});