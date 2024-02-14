<?php

use Keepsuit\LaravelOpenTelemetry\Instrumentation;

return [
    /**
     * Service name
     */
    'service_name' => env('OTEL_SERVICE_NAME', \Illuminate\Support\Str::slug(env('APP_NAME', 'laravel-app'))),

    /**
     * Traces sampler
     */
    'sampler' => [
        /**
         * Wraps the sampler in a parent based sampler
         */
        'parent' => env('OTEL_TRACES_SAMPLER_PARENT', true),

        /**
         * Sampler type
         * Supported values: "always_on", "always_off", "traceidratio"
         */
        'type' => env('OTEL_TRACES_SAMPLER_TYPE', 'always_on'),

        'args' => [
            /**
             * Sampling ratio for traceidratio sampler
             */
            'ratio' => env('OTEL_TRACES_SAMPLER_TRACEIDRATIO_RATIO', 0.05),
        ],
    ],

    /**
     * Traces exporter
     * Supported: "zipkin", "http", "grpc", "console", "null"
     */
    'exporter' => env('OTEL_TRACES_EXPORTER', 'http'),

    /**
     * Comma separated list of propagators to use.
     * Supports any otel propagator, for example: "tracecontext", "baggage", "b3", "b3multi", "none"
     */
    'propagators' => env('OTEL_PROPAGATORS', 'tracecontext'),

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
        'otlp' => [
            'endpoint' => env('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT', env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318')),

            // Supported: "grpc", "http/protobuf", "http/json"
            'protocol' => env('OTEL_EXPORTER_OTLP_TRACES_PROTOCOL', env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http')),
        ],

        'zipkin' => [
            'endpoint' => env('OTEL_ZIPKIN_HTTP_ENDPOINT', 'http://localhost:9411'),
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
