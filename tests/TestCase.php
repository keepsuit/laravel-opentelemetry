<?php

namespace Keepsuit\LaravelOpenTelemetry\Tests;

use Keepsuit\LaravelOpenTelemetry\LaravelOpenTelemetryServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelOpenTelemetryServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('opentelemetry.exporter', null);
    }
}
