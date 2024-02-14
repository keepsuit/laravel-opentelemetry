<?php

use Keepsuit\LaravelOpenTelemetry\Instrumentation;

return [
    /**
     * Service name
     */
    'service_name' => env('OTEL_SERVICE_NAME', \Illuminate\Support\Str::slug(env('APP_NAME', 'laravel-app'))),

    /**
     * Enable tracing
     * Valid values: 'true', 'false', 'parent'
     */
    'enabled' => env('OTEL_ENABLED', true),

    /**
     * Exporter to use
     * Supported: 'zipkin', 'http', 'grpc', 'console', 'null'
     */
    'exporter' => env('OTEL_EXPORTER', 'http'),

    /**
     * Propagator to use
     * Supported: 'b3', 'b3multi', 'tracecontext',
     */
    'propagator' => env('OTEL_PROPAGATOR', 'tracecontext'),

    /**
     * List of instrumentation used for application tracing
     */
    'instrumentation' => [
        Instrumentation\HttpServerInstrumentation::class => [
            'enabled' => env('OTEL_INSTRUMENTATION_HTTP_SERVER', true),
            'excluded_paths' => [],
            'allowed_headers' => [],
            'sensitive_headers' => [],
        ],

        Instrumentation\HttpClientInstrumentation::class => [
            'enabled' => env('OTEL_INSTRUMENTATION_HTTP_CLIENT', true),
            'allowed_headers' => [],
            'sensitive_headers' => [],
        ],

        Instrumentation\QueryInstrumentation::class => env('OTEL_INSTRUMENTATION_QUERY', true),

        Instrumentation\RedisInstrumentation::class => env('OTEL_INSTRUMENTATION_REDIS', true),

        Instrumentation\QueueInstrumentation::class => env('OTEL_INSTRUMENTATION_QUEUE', true),

        Instrumentation\CacheInstrumentation::class => env('OTEL_INSTRUMENTATION_CACHE', true),

        Instrumentation\EventInstrumentation::class => [
            'enabled' => env('OTEL_INSTRUMENTATION_EVENT', true),
            'ignored' => [],
        ],
    ],

    /**
     * Exporters config
     */
    'exporters' => [
        'zipkin' => [
            'endpoint' => env('OTEL_ZIPKIN_HTTP_ENDPOINT', 'http://localhost:9411'),
        ],

        'http' => [
            'endpoint' => env('OTEL_OTLP_HTTP_ENDPOINT', 'http://localhost:4318'),
        ],

        'grpc' => [
            'endpoint' => env('OTEL_OTLP_GRPC_ENDPOINT', 'http://localhost:4317'),
        ],
    ],

    'logs' => [
        /**
         * Inject active trace id in log context
         */
        'inject_trace_id' => true,

        /**
         * Context field name for trace id
         */
        'trace_id_field' => 'traceId',
    ],
];
