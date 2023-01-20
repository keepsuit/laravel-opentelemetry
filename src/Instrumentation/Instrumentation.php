<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

interface Instrumentation
{
    public function register(array $options): void;
}
