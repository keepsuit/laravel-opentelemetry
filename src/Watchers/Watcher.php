<?php

namespace Keepsuit\LaravelOpenTelemetry\Watchers;

use Illuminate\Contracts\Foundation\Application;

abstract class Watcher
{
    public function __construct(
        /** @var array<string, mixed> */
        public array $options = []
    ) {
    }

    abstract public function register(Application $app): void;
}
