<?php

namespace Keepsuit\LaravelOpenTelemetry\Tests\Support;

use Exception;

class KeepsuitException extends Exception
{
    public static function create(): self
    {
        return new self('Keepsuit Exception thrown!');
    }
}
