<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Arr;
use Keepsuit\LaravelOpenTelemetry\Support\HttpServer\TraceRequestMiddleware;

class HttpServerInstrumentation implements Instrumentation
{
    use HandlesHttpHeaders;

    protected static array $excludedPaths = [];

    /**
     * @return array<string>
     */
    public static function getExcludedPaths(): array
    {
        return static::$excludedPaths;
    }

    public function register(array $options): void
    {
        static::$excludedPaths = array_map(
            fn (string $path) => ltrim($path, '/'),
            Arr::get($options, 'excluded_paths', [])
        );

        static::$allowedHeaders = $this->normalizeHeaders(Arr::get($options, 'allowed_headers', []));

        static::$sensitiveHeaders = array_merge(
            $this->normalizeHeaders(Arr::get($options, 'sensitive_headers', [])),
            $this->defaultSensitiveHeaders
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
