<?php

use Keepsuit\LaravelOpenTelemetry\Watchers;

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
     * Http paths not to trace
     */
    'excluded_paths' => [],

    /**
     * Grpc services not to trace
     */
    'excluded_services' => [],

    /**
     * List of watcher used for application tracing
     */
    'watchers' => [
        Watchers\QueryWatcher::class => env('OT_WATCHER_QUERY', true),

        Watchers\RedisWatcher::class => env('OT_WATCHER_REDIS', true),

        Watchers\QueueWatcher::class => env('OT_WATCHER_QUEUE', true),

        Watchers\LighthouseWatcher::class => env('OT_WATCHER_LIGHTHOUSE', true),
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
