<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation\Support\Http;

use Illuminate\Support\Arr;

/**
 * @internal
 */
trait HandlesHttpQueryString
{
    /**
     * @var array<string>
     */
    protected array $defaultSensitiveQueryParams = [
        'AWSAccessKeyId',
        'Signature',
        'sig',
        'X-Goog-Signature',
    ];

    /**
     * @var array<string>
     */
    protected static array $sensitiveQueryParameters = [];

    /**
     * @return array<string>
     */
    public static function getSensitiveQueryParameters(): array
    {
        return static::$sensitiveQueryParameters;
    }

    public static function redactQueryString(string $queryString): string
    {
        if ($queryString === '') {
            return '';
        }

        $query = [];
        parse_str($queryString, $query);

        return http_build_query(
            Arr::map($query, fn (mixed $value, string $key) => in_array(strtolower($key), static::getSensitiveQueryParameters(), true) ? 'REDACTED' : $value)
        );
    }

    /**
     * @param  array<string>  $parameters
     * @return array<string>
     */
    protected function normalizeQueryParameters(array $parameters): array
    {
        return Arr::map(
            $parameters,
            fn (string $parameter) => strtolower(trim($parameter)),
        );
    }
}
