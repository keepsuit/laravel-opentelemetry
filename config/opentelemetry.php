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
     * Supported: 'jaeger', 'zipkin', 'null'
     */
    'exporter' => env('OT_EXPORTER', 'jaeger'),

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

        Watchers\LighthouseWatcher::class => env('OT_WATCHER_LIGHTHOUSE', true),
    ],

    /**
     * Exporters config
     */
    'exporters' => [
        'jaeger' => [
            'endpoint' => env('OT_JAEGER_ENDPOINT', 'http://localhost:9411/api/v2/spans'),
        ],

        'jaeger-http' => [
            'endpoint' => env('OT_JAEGER_HTTP_ENDPOINT', 'http://localhost:14268/api/traces'),
        ],

        'zipkin' => [
            'endpoint' => env('OT_ZIPKIN_HTTP_ENDPOINT', 'http://localhost:9411/api/v2/spans'),
        ],

        'otlp-http' => [
            'endpoint' => env('OT_OTLP_HTTP_ENDPOINT', 'http://localhost:4318/v1/traces'),
        ],

        'otlp-grpc' => [
            'endpoint' => env('OT_OTLP_GRPC_ENDPOINT', 'http://localhost:4317'),
        ],
    ],
];
