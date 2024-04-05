<?php

use Keepsuit\LaravelOpenTelemetry\Instrumentation;

return [
    /**
     * Service name
     */
    'service_name' => env('OTEL_SERVICE_NAME', \Illuminate\Support\Str::slug(env('APP_NAME', 'laravel-app'))),

    /**
     * Comma separated list of propagators to use.
     * Supports any otel propagator, for example: "tracecontext", "baggage", "b3", "b3multi", "none"
     */
    'propagators' => env('OTEL_PROPAGATORS', 'tracecontext'),

    /**
     * OpenTelemetry Traces configuration
     */
    'traces' => [
        /**
         * Traces exporter
         * This should be the key of one of the exporters defined in the exporters section
         */
        'exporter' => env('OTEL_TRACES_EXPORTER', 'otlp'),

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
    ],

    /**
     * OpenTelemetry logs configuration
     */
    'logs' => [
        /**
         * Logs exporter
         * This should be the key of one of the exporters defined in the exporters section
         * Supported drivers: "otlp", "console", "null"
         */
        'exporter' => env('OTEL_LOGS_EXPORTER', 'otlp'),

        /**
         * Inject active trace id in log context
         */
        'inject_trace_id' => true,

        /**
         * Context field name for trace id
         */
        'trace_id_field' => 'traceId',
    ],

    /**
     * OpenTelemetry exporters
     *
     * Here you can configure exports used by traces and logs.
     * If you want to use the same protocol with different endpoints,
     * you can copy the exporter with a different and change the endpoint
     *
     * Supported drivers: "otlp", "zipkin", "console", "null"
     */
    'exporters' => [
        'otlp' => [
            'driver' => 'otlp',
            'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318'),
            // Supported: "grpc", "http/protobuf", "http/json"
            'protocol' => env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http/protobuf'),
        ],

        'zipkin' => [
            'driver' => 'zipkin',
            'endpoint' => env('OTEL_EXPORTER_ZIPKIN_ENDPOINT', 'http://localhost:9411'),
        ],
    ],

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
];
