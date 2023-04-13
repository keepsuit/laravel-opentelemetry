<?php

use Keepsuit\LaravelOpenTelemetry\Instrumentation;

return [
    /**
     * Service name
     */
    'service_name' => \Illuminate\Support\Str::slug(env('APP_NAME', 'laravel-app')),

    /**
     * Enable tracing
     * Valid values: 'true', 'false', 'parent'
     */
    'enabled' => env('OT_ENABLED', true),

    /**
     * Exporter to use
     * Supported: 'zipkin', 'http', 'grpc', 'console', 'null'
     */
    'exporter' => env('OT_EXPORTER', 'http'),

    /**
     * Propagator to use
     * Supported: 'b3', 'b3multi', 'tracecontext',
     */
    'propagator' => env('OT_PROPAGATOR', 'tracecontext'),

    /**
     * List of instrumentation used for application tracing
     */
    'instrumentation' => [
        Instrumentation\HttpServerInstrumentation::class => [
            'enabled' => env('OT_INSTRUMENTATION_HTTP_SERVER', true),
            'excluded_paths' => [],
        ],

        Instrumentation\HttpClientInstrumentation::class => env('OT_INSTRUMENTATION_HTTP_CLIENT', true),

        Instrumentation\QueryInstrumentation::class => env('OT_INSTRUMENTATION_QUERY', true),

        Instrumentation\RedisInstrumentation::class => env('OT_INSTRUMENTATION_REDIS', true),

        Instrumentation\QueueInstrumentation::class => env('OT_INSTRUMENTATION_QUEUE', true),

        Instrumentation\CacheInstrumentation::class => env('OT_INSTRUMENTATION_CACHE', true),

        Instrumentation\EventInstrumentation::class => [
            'enabled' => env('OT_INSTRUMENTATION_EVENT', true),
            'ignored' => [],
        ],
    ],

    /**
     * Exporters config
     */
    'exporters' => [
        'zipkin' => [
            'endpoint' => env('OT_ZIPKIN_HTTP_ENDPOINT', 'http://localhost:9411'),
        ],

        'http' => [
            'endpoint' => env('OT_OTLP_HTTP_ENDPOINT', 'http://localhost:4318'),
        ],

        'grpc' => [
            'endpoint' => env('OT_OTLP_GRPC_ENDPOINT', 'http://localhost:4317'),
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
