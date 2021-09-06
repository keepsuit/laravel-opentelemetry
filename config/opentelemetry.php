<?php

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
     * Path not to trace
     */
    'excluded_paths' => [],

    /**
     * Exporters config
     */
    'exporters' => [
        'jaeger' => [
            'endpoint' => 'http://localhost:9411/api/v2/spans',
        ],

        'zipkin' => [
            'endpoint' => 'http://localhost:9411/api/v2/spans',
        ],
    ],
];
