<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Arr;
use Keepsuit\LaravelOpenTelemetry\Support\HttpServer\TraceRequestMiddleware;

class HttpServerInstrumentation implements Instrumentation
{
    protected const DEFAULT_SENSITIVE_HEADERS = [
        'authorization',
        'php-auth-pw',
        'cookie',
        'set-cookie',
    ];

    protected static array $excludedPaths = [];

    protected static array $allowedHeaders = [];

    protected static array $sensitiveHeaders = [];

    /**
     * @return array<string>
     */
    public static function getExcludedPaths(): array
    {
        return static::$excludedPaths;
    }

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

    public function register(array $options): void
    {
        static::$excludedPaths = array_map(
            fn (string $path) => ltrim($path, '/'),
            Arr::get($options, 'excluded_paths', [])
        );

        static::$allowedHeaders = array_map(
            fn (string $header) => strtolower(trim($header)),
            Arr::get($options, 'allowed_headers', [])
        );
        static::$sensitiveHeaders = array_merge(
            array_map(
                fn (string $header) => strtolower(trim($header)),
                Arr::get($options, 'sensitive_headers', [])
            ),
            self::DEFAULT_SENSITIVE_HEADERS
        );

        $this->injectMiddleware(app(Kernel::class));
    }

    protected function injectMiddleware(Kernel $kernel): void
    {
        if (! $kernel instanceof \Illuminate\Foundation\Http\Kernel) {
            return;
        }

        if (! $kernel->hasMiddleware(TraceRequestMiddleware::class)) {
            $kernel->prependMiddleware(TraceRequestMiddleware::class);
        }
    }
}
