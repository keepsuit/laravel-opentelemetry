<?php

namespace Keepsuit\LaravelOpentelemetry;

use Keepsuit\LaravelOpentelemetry\Commands\LaravelOpentelemetryCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelOpentelemetryServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-opentelemetry')
            ->hasConfigFile()
            ->hasCommand(LaravelOpentelemetryCommand::class);
    }
}
