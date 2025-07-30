<?php

namespace Keepsuit\LaravelOpenTelemetry\Tests\Support;

use Exception;

class TestException extends Exception
{
    public static function create(): self
    {
        return new self('Exception thrown!');
    }
}
