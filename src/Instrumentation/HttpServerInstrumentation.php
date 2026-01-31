<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Support\Arr;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\Support\Http\HandlesHttpHeaders;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\Support\Http\HandlesHttpQueryString;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\Support\Http\Server\TraceRequestMiddleware;
use OpenTelemetry\API\Common\Time\Clock;

class HttpServerInstrumentation implements Instrumentation
{
    use HandlesHttpHeaders;
    use HandlesHttpQueryString;

    protected static array $excludedPaths = [];

    protected static array $excludedMethods = [];

    protected static ?int $bootedTimestamp = null;

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
    public static function getExcludedMethods(): array
    {
        return static::$excludedMethods;
    }

    public static function getBootedTimestamp(): ?int
    {
        return static::$bootedTimestamp;
    }

    public function register(array $options): void
    {
        static::$excludedPaths = array_map(
            fn (string $path) => ltrim($path, '/'),
            Arr::get($options, 'excluded_paths', []),
        );

        static::$excludedMethods = array_map(
            fn (string $method) => strtoupper($method),
            Arr::get($options, 'excluded_methods', []),
        );

        static::$allowedHeaders = $this->normalizeHeaders(Arr::get($options, 'allowed_headers', []));

        static::$sensitiveQueryParameters = array_merge(
            $this->normalizeQueryParameters(Arr::get($options, 'sensitive_query_parameters', [])),
            $this->defaultSensitiveQueryParams
        );

        static::$sensitiveHeaders = array_merge(
            $this->normalizeHeaders(Arr::get($options, 'sensitive_headers', [])),
            $this->defaultSensitiveHeaders,
        );

        static::$bootedTimestamp = null;
        app()->booted(function () {
            static::$bootedTimestamp = Clock::getDefault()->now();
        });

        $this->injectMiddleware(app(HttpKernelContract::class));
    }

    protected function injectMiddleware(HttpKernelContract $kernel): void
    {
        if (! $kernel instanceof FoundationHttpKernel) {
            return;
        }

        if (! $kernel->hasMiddleware(TraceRequestMiddleware::class)) {
            $kernel->prependMiddleware(TraceRequestMiddleware::class);
        }
    }
}
