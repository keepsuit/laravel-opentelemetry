<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation\Support\Http;

use OpenTelemetry\SemConv\Attributes\HttpAttributes;

final class HttpMethod
{
    private const DEFAULT_KNOWN_METHODS = ['CONNECT', 'DELETE', 'GET', 'HEAD', 'OPTIONS', 'PATCH', 'POST', 'PUT', 'QUERY', 'TRACE'];

    /**
     * Returns the method if known, `_OTHER` otherwise.
     */
    public static function normalize(string $method): string
    {
        return in_array($method, self::knownMethods(), true) ? $method : HttpAttributes::HTTP_REQUEST_METHOD_VALUE_OTHER;
    }

    /**
     * Span name prefix: `HTTP` replaces `_OTHER`.
     */
    public static function spanName(string $normalizedMethod): string
    {
        return $normalizedMethod === HttpAttributes::HTTP_REQUEST_METHOD_VALUE_OTHER ? 'HTTP' : $normalizedMethod;
    }

    /**
     * @return array<string>
     */
    private static function knownMethods(): array
    {
        $override = config('opentelemetry.http_known_methods');

        if (! is_string($override)) {
            return self::DEFAULT_KNOWN_METHODS;
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $override)), fn (string $method) => $method !== ''));
    }
}
