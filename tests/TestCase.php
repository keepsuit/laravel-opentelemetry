<?php

namespace Keepsuit\LaravelOpenTelemetry\Tests;

use Illuminate\Support\Str;
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
        config()->set('opentelemetry.exporter', null);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        config()->set('queue.default', 'redis');
        config()->set('database.redis.options.prefix', sprintf('%s_', Str::uuid()));
    }
}
