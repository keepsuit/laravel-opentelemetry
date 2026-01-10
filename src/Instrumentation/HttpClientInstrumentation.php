<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Closure;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Keepsuit\LaravelOpenTelemetry\Support\HttpClient\GuzzleTraceMiddleware;
use Keepsuit\LaravelOpenTelemetry\Support\InstrumentationUtilities;
use Psr\Http\Message\RequestInterface;

class HttpClientInstrumentation implements Instrumentation
{
    use HandlesHttpHeaders;
    use InstrumentationUtilities;

    protected static ?Closure $routeNameResolver = null;

    public function register(array $options): void
    {
        static::$allowedHeaders = $this->normalizeHeaders(Arr::get($options, 'allowed_headers', []));

        static::$sensitiveHeaders = array_merge(
            $this->normalizeHeaders(Arr::get($options, 'sensitive_headers', [])),
            $this->defaultSensitiveHeaders
        );

        $this->registerWithTraceMacro();

        $manual = $options['manual'] ?? false;
        if ($manual !== true) {
            $this->callAfterResolving(Factory::class, $this->registerGlobalMiddleware(...));
        }
    }

    protected function registerWithTraceMacro(): void
    {
        PendingRequest::macro('withTrace', function () {
            /** @var PendingRequest $this */
            return $this->withMiddleware(GuzzleTraceMiddleware::make());
        });
    }

    protected function registerGlobalMiddleware(Factory $factory): void
    {
        $factory->globalMiddleware(GuzzleTraceMiddleware::make());

        PendingRequest::macro('withTrace', function () {
            return $this;
        });
    }

    public static function setRouteNameResolver(Closure $resolver): void
    {
        static::$routeNameResolver = $resolver;
    }

    public static function routeName(RequestInterface $request): ?string
    {
        if (static::$routeNameResolver === null) {
            return null;
        }

        return (static::$routeNameResolver)($request);
    }
}
