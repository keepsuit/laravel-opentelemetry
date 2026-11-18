<?php

use Illuminate\Support\Str;
use Keepsuit\LaravelOpenTelemetry\Instrumentation;
use Keepsuit\LaravelOpenTelemetry\Support\ResourceAttributesParser;
use Keepsuit\LaravelOpenTelemetry\TailSampling;
use Keepsuit\LaravelOpenTelemetry\WorkerMode;
use OpenTelemetry\SDK\Common\Configuration\Variables;

return [
    /**
     * When set to true, Opentelemetry SDK will be disabled
     */
    'disabled' => filter_var(env(Variables::OTEL_SDK_DISABLED, false), FILTER_VALIDATE_BOOLEAN),

    /**
     * Service name
     */
    'service_name' => env(Variables::OTEL_SERVICE_NAME, Str::slug((string) env('APP_NAME', 'laravel-app'))),

    /**
     * Service instance id
     * Long-running processes: unique per process. Per-request runtimes (PHP-FPM,
     * FrankenPHP without worker mode): stable per host or pod, with Delta temporality.
     * If not set, a random id is generated on each request (a new metric series per request).
     */
    'service_instance_id' => env('OTEL_SERVICE_INSTANCE_ID'),

    /**
     * Additional resource attributes
     * Key-value pairs of resource attributes to add to all telemetry data.
     * By default, reads and parses OTEL_RESOURCE_ATTRIBUTES environment variable (which should be in the format 'key1=value1,key2=value2').
     */
    'resource_attributes' => ResourceAttributesParser::parse((string) env(Variables::OTEL_RESOURCE_ATTRIBUTES, '')),

    /**
     * Include authenticated user context on traces and logs.
     */
    'user_context' => filter_var(env('OTEL_USER_CONTEXT', true), FILTER_VALIDATE_BOOLEAN),

    /**
     * Comma separated list of propagators to use.
     * Supports any otel propagator, for example: "tracecontext", "baggage", "b3", "b3multi", "none"
     */
    'propagators' => env(Variables::OTEL_PROPAGATORS, 'tracecontext'),

    /**
     * OpenTelemetry Meter configuration
     */
    'metrics' => [
        /**
         * Metrics exporter
         * This should be the key of one of the exporters defined in the exporters section
         * Supported drivers: "otlp", "console", "memory", "null"
         */
        'exporter' => env(Variables::OTEL_METRICS_EXPORTER, 'otlp'),
    ],

    /**
     * OpenTelemetry Traces configuration
     */
    'traces' => [
        /**
         * Traces exporter
         * This should be the key of one of the exporters defined in the exporters section
         * Supported drivers: "otlp", "zipkin", "console", "memory", "null"
         */
        'exporter' => env(Variables::OTEL_TRACES_EXPORTER, 'otlp'),

        /**
         * Traces sampler
         */
        'sampler' => [
            /**
             * Wraps the sampler in a parent based sampler
             */
            'parent' => filter_var(env('OTEL_TRACES_SAMPLER_PARENT', true), FILTER_VALIDATE_BOOLEAN),

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

            'tail_sampling' => [
                'enabled' => filter_var(env('OTEL_TRACES_TAIL_SAMPLING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
                // Maximum time to wait for the end of the trace before making a sampling decision (in milliseconds)
                'decision_wait' => (int) env('OTEL_TRACES_TAIL_SAMPLING_DECISION_WAIT', 5000),

                'rules' => [
                    TailSampling\Rules\ErrorsRule::class => filter_var(env('OTEL_TRACES_TAIL_SAMPLING_RULE_KEEP_ERRORS', true), FILTER_VALIDATE_BOOLEAN),
                    TailSampling\Rules\SlowTraceRule::class => [
                        'enabled' => filter_var(env('OTEL_TRACES_TAIL_SAMPLING_RULE_SLOW_TRACES', true), FILTER_VALIDATE_BOOLEAN),
                        'threshold_ms' => (int) env('OTEL_TRACES_TAIL_SAMPLING_SLOW_TRACES_THRESHOLD_MS', 2000),
                    ],
                ],
            ],
        ],

        /**
         * Traces span processors.
         * Processors classes must implement OpenTelemetry\SDK\Trace\SpanProcessorInterface
         *
         * Example: YourTracesSpanProcessor::class
         */
        'processors' => [],
    ],

    /**
     * OpenTelemetry logs configuration
     */
    'logs' => [
        /**
         * Logs exporter
         * This should be the key of one of the exporters defined in the exporters section
         * Supported drivers: "otlp", "console", "memory", "null"
         */
        'exporter' => env(Variables::OTEL_LOGS_EXPORTER, 'otlp'),

        'level' => env('LOG_LEVEL', 'debug'),

        /**
         * Inject active trace id in log context
         *
         * When using the OpenTelemetry logger, the trace id is always injected in the exported log record.
         * This option allows to inject the trace id in the log context for other loggers.
         */
        'inject_trace_id' => true,

        /**
         * Context field name for trace id
         */
        'trace_id_field' => 'trace_id',

        /**
         * Logs record processors.
         * Processors classes must implement OpenTelemetry\SDK\Logs\LogRecordProcessorInterface
         *
         * Example: YourLogRecordProcessor::class
         */
        'processors' => [],
    ],

    /**
     * OpenTelemetry exporters
     *
     * Here you can configure exports used by metrics, traces and logs.
     * If you want to use the same protocol with different endpoints,
     * you can copy the exporter with a different and change the endpoint
     *
     * Supported drivers: "otlp", "zipkin" (only traces), "console", "memory", "null"
     */
    'exporters' => [
        'otlp' => [
            'driver' => 'otlp',
            'endpoint' => env(Variables::OTEL_EXPORTER_OTLP_ENDPOINT, 'http://localhost:4318'),
            /**
             * Supported protocols: "grpc", "http/protobuf", "http/json"
             */
            'protocol' => env(Variables::OTEL_EXPORTER_OTLP_PROTOCOL, 'http/protobuf'),
            'max_retries' => (int) env('OTEL_EXPORTER_OTLP_MAX_RETRIES', 3),
            'traces_timeout' => (int) env(Variables::OTEL_EXPORTER_OTLP_TRACES_TIMEOUT, env(Variables::OTEL_EXPORTER_OTLP_TIMEOUT, 10000)),
            'traces_headers' => (string) env(Variables::OTEL_EXPORTER_OTLP_TRACES_HEADERS, env(Variables::OTEL_EXPORTER_OTLP_HEADERS, '')),
            /**
             * Override protocol for traces export
             */
            'traces_protocol' => env(Variables::OTEL_EXPORTER_OTLP_TRACES_PROTOCOL),
            'metrics_timeout' => (int) env(Variables::OTEL_EXPORTER_OTLP_METRICS_TIMEOUT, env(Variables::OTEL_EXPORTER_OTLP_TIMEOUT, 10000)),
            'metrics_headers' => (string) env(Variables::OTEL_EXPORTER_OTLP_METRICS_HEADERS, env(Variables::OTEL_EXPORTER_OTLP_HEADERS, '')),
            /**
             * Override protocol for metrics export
             */
            'metrics_protocol' => env(Variables::OTEL_EXPORTER_OTLP_METRICS_PROTOCOL),
            /**
             * Preferred metrics temporality
             * Supported values: "delta", "cumulative" (case-insensitive)
             */
            'metrics_temporality' => env(Variables::OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE),
            'logs_timeout' => (int) env(Variables::OTEL_EXPORTER_OTLP_LOGS_TIMEOUT, env(Variables::OTEL_EXPORTER_OTLP_TIMEOUT, 10000)),
            'logs_headers' => (string) env(Variables::OTEL_EXPORTER_OTLP_LOGS_HEADERS, env(Variables::OTEL_EXPORTER_OTLP_HEADERS, '')),
            /**
             * Override protocol for logs export
             */
            'logs_protocol' => env(Variables::OTEL_EXPORTER_OTLP_LOGS_PROTOCOL),
        ],

        'zipkin' => [
            'driver' => 'zipkin',
            'endpoint' => env(Variables::OTEL_EXPORTER_ZIPKIN_ENDPOINT, 'http://localhost:9411'),
            'timeout' => env(Variables::OTEL_EXPORTER_ZIPKIN_TIMEOUT, 10000),
            'max_retries' => (int) env('OTEL_EXPORTER_ZIPKIN_MAX_RETRIES', 3),
        ],
    ],

    /**
     * Comma separated, case-sensitive list of known HTTP methods. Other methods are recorded as "_OTHER".
     * When set, it replaces the default list (RFC 9110 methods, PATCH and QUERY).
     */
    'http_known_methods' => env('OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS'),

    /**
     * List of instrumentation used for application tracing
     */
    'instrumentation' => [
        Instrumentation\HttpServerInstrumentation::class => [
            'enabled' => filter_var(env('OTEL_INSTRUMENTATION_HTTP_SERVER', true), FILTER_VALIDATE_BOOLEAN),
            'excluded_paths' => [],
            'excluded_methods' => [],
            'allowed_headers' => [],
            'sensitive_headers' => [],
            'sensitive_query_parameters' => [],
        ],

        Instrumentation\HttpClientInstrumentation::class => [
            'enabled' => filter_var(env('OTEL_INSTRUMENTATION_HTTP_CLIENT', true), FILTER_VALIDATE_BOOLEAN),
            'manual' => false, // When set to true, you need to call `withTrace()` on the request to enable tracing
            'allowed_headers' => [],
            'sensitive_headers' => [],
            'sensitive_query_parameters' => [],
        ],

        Instrumentation\QueryInstrumentation::class => filter_var(env('OTEL_INSTRUMENTATION_QUERY', true), FILTER_VALIDATE_BOOLEAN),

        Instrumentation\RedisInstrumentation::class => filter_var(env('OTEL_INSTRUMENTATION_REDIS', true), FILTER_VALIDATE_BOOLEAN),

        Instrumentation\QueueInstrumentation::class => filter_var(env('OTEL_INSTRUMENTATION_QUEUE', true), FILTER_VALIDATE_BOOLEAN),

        Instrumentation\CacheInstrumentation::class => filter_var(env('OTEL_INSTRUMENTATION_CACHE', true), FILTER_VALIDATE_BOOLEAN),

        Instrumentation\EventInstrumentation::class => [
            'enabled' => filter_var(env('OTEL_INSTRUMENTATION_EVENT', true), FILTER_VALIDATE_BOOLEAN),
            'excluded' => [],
        ],

        Instrumentation\ViewInstrumentation::class => filter_var(env('OTEL_INSTRUMENTATION_VIEW', true), FILTER_VALIDATE_BOOLEAN),

        Instrumentation\LivewireInstrumentation::class => filter_var(env('OTEL_INSTRUMENTATION_LIVEWIRE', true), FILTER_VALIDATE_BOOLEAN),

        Instrumentation\ConsoleInstrumentation::class => [
            'enabled' => filter_var(env('OTEL_INSTRUMENTATION_CONSOLE', true), FILTER_VALIDATE_BOOLEAN),
            'commands' => [],
        ],

        Instrumentation\ScoutInstrumentation::class => filter_var(env('OTEL_INSTRUMENTATION_SCOUT', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /**
     * Worker mode detection configuration
     *
     * Detects worker modes (e.g., Octane, Horizon, Queue) and optimizes OpenTelemetry
     * behavior for long-running processes.
     */
    'worker_mode' => [
        /**
         * Flush after each iteration (e.g. http request, queue job).
         * If false, flushes are batched and executed periodically and on shutdown.
         */
        'flush_after_each_iteration' => filter_var(env('OTEL_WORKER_MODE_FLUSH_AFTER_EACH_ITERATION', false), FILTER_VALIDATE_BOOLEAN),

        /**
         * Flush interval in seconds.
         * When running in worker mode, all signals are exported at this interval.
         * Note: This setting is ignored if 'flush_after_each_iteration' is true.
         * Note: The interval is checked after each iteration, so the actual interval may be longer
         */
        'flush_interval' => (int) env('OTEL_WORKER_MODE_FLUSH_INTERVAL', env('OTEL_WORKER_MODE_COLLECT_INTERVAL', 60)),

        /**
         * Detectors to use for worker mode detection
         *
         * Every detector that returns true is registered.
         * Custom detectors implementing DetectorInterface can be added here.
         *
         * Built-in detectors:
         * - OctaneDetector: Detects Laravel Octane
         * - QueueDetector: Detects Laravel default queue worker and Laravel Horizon
         */
        'detectors' => [
            WorkerMode\Detectors\OctaneWorkerModeDetector::class,
            WorkerMode\Detectors\QueueWorkerModeDetector::class,
        ],
    ],
];
