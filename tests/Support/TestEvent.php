<?php

namespace Keepsuit\LaravelOpenTelemetry\Tests\Support;

class TestEvent
{
    public function __construct(public string $value)
    {
    }
}
