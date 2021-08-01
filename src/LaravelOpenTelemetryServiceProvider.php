<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Keepsuit\LaravelOpenTelemetry\Commands\LaravelOpenTelemetryCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelOpenTelemetryServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-opentelemetry')
            ->hasConfigFile()
            ->hasCommand(LaravelOpenTelemetryCommand::class);
    }
}
