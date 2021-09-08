<?php

namespace Keepsuit\LaravelOpenTelemetry\Watchers;

use Illuminate\Contracts\Foundation\Application;

abstract class Watcher
{
    public array $options = [];

    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    abstract public function register(Application $app): void;
}
