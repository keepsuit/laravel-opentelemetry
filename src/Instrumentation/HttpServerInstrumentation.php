<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Exceptions\Handler as FoundationExceptionHandler;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Support\Arr;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Support\HttpServer\TraceRequestMiddleware;
use OpenTelemetry\API\Trace\StatusCode;
use Throwable;

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
            Arr::get($options, 'excluded_paths', []),
        );

        static::$allowedHeaders = $this->normalizeHeaders(Arr::get($options, 'allowed_headers', []));

        static::$sensitiveHeaders = array_merge(
            $this->normalizeHeaders(Arr::get($options, 'sensitive_headers', [])),
            $this->defaultSensitiveHeaders,
        );

        $this->recordExceptionInSpan(app(ExceptionHandlerContract::class));
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

    protected function recordExceptionInSpan(ExceptionHandlerContract $handler): void
    {
        if ($handler instanceof FoundationExceptionHandler) {
            $handler->reportable(fn (Throwable $e) => Tracer::activeSpan()
                ->recordException($e)
                ->setStatus(StatusCode::STATUS_ERROR)
                ->end(),
            );
        }
    }
}
