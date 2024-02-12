<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Keepsuit\LaravelOpenTelemetry\Support\HttpClient\GuzzleTraceMiddleware;

class HttpClientInstrumentation implements Instrumentation
{
    use HandlesHttpHeaders;

    public function register(array $options): void
    {
        static::$allowedHeaders = $this->normalizeHeaders(Arr::get($options, 'allowed_headers', []));

        static::$sensitiveHeaders = array_merge(
            $this->normalizeHeaders(Arr::get($options, 'sensitive_headers', [])),
            $this->defaultSensitiveHeaders
        );

        $this->registerWithTraceMacro();
    }

    protected function registerWithTraceMacro(): void
    {
        PendingRequest::macro('withTrace', function () {
            /** @var PendingRequest $this */
            return $this->withMiddleware(GuzzleTraceMiddleware::make());
        });
    }
}
