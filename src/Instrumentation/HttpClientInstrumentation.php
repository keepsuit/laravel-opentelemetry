<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Keepsuit\LaravelOpenTelemetry\Support\HttpClient\GuzzleTraceMiddleware;

class HttpClientInstrumentation implements Instrumentation
{
    public function register(array $options): void
    {
        $this->registerWithTraceMacro();
    }

    protected function registerWithTraceMacro(): void
    {
        Http::macro('withTrace', function () {
            /** @var PendingRequest $this */
            return $this->withMiddleware(GuzzleTraceMiddleware::make());
        });
    }
}
