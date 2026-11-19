<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation\Support\Http;

use OpenTelemetry\SemConv\Attributes\HttpAttributes;

final class HttpMethod
{
    private const KNOWN_METHODS = ['CONNECT', 'DELETE', 'GET', 'HEAD', 'OPTIONS', 'PATCH', 'POST', 'PUT', 'QUERY', 'TRACE'];

    /**
     * Returns the method if known, `_OTHER` otherwise.
     */
    public static function normalize(string $method): string
    {
        return in_array($method, self::KNOWN_METHODS, true) ? $method : HttpAttributes::HTTP_REQUEST_METHOD_VALUE_OTHER;
    }

    /**
     * Span name prefix: `HTTP` replaces `_OTHER`.
     */
    public static function spanName(string $normalizedMethod): string
    {
        return $normalizedMethod === HttpAttributes::HTTP_REQUEST_METHOD_VALUE_OTHER ? 'HTTP' : $normalizedMethod;
    }
}
