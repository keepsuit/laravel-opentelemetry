<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Support\Arr;

/**
 * @internal
 */
trait HandlesHttpHeaders
{
    /**
     * @var array<string>
     */
    protected array $defaultSensitiveHeaders = [
        'authorization',
        'php-auth-pw',
        'cookie',
        'set-cookie',
    ];

    /**
     * @var array<string>
     */
    protected static array $allowedHeaders = [];

    /**
     * @var array<string>
     */
    protected static array $sensitiveHeaders = [];

    /**
     * @return array<string>
     */
    public static function getAllowedHeaders(): array
    {
        return static::$allowedHeaders;
    }

    /**
     * @return array<string>
     */
    public static function getSensitiveHeaders(): array
    {
        return static::$sensitiveHeaders;
    }

    /**
     * @param  array<string>  $headers
     * @return array<string>
     */
    protected function normalizeHeaders(array $headers): array
    {
        return Arr::map(
            $headers,
            fn (string $header) => strtolower(trim($header)),
        );
    }
}
