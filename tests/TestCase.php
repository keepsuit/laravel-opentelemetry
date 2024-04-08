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
        $app['config']->set('opentelemetry.traces.exporter', null);
        $app['config']->set('opentelemetry.logs.exporter', null);

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('database.redis.options.prefix', sprintf('%s_', Str::uuid()));

        $app['config']->set('queue.default', 'redis');
        $app['config']->set('queue.failed.driver', null);

        $app['config']->set('logging.default', 'otlp');
    }
}
