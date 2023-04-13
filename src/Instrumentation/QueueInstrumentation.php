<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\QueueManager;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;

class QueueInstrumentation implements Instrumentation
{
    use SpanTimeAdapter;

    public function register(array $options): void
    {
        if (extension_loaded('opentelemetry')) {
            $this->traceDispatchCalls();
        }

        if (app()->resolved('queue')) {
            $this->registerQueueInterceptor(app('queue'));
        } else {
            app()->afterResolving('queue', fn ($queue) => $this->registerQueueInterceptor($queue));
        }

        $this->recordJobStart();
        $this->recordJobEnd();
    }

    protected function recordJobStart(): void
    {
        app('events')->listen(JobProcessing::class, function (JobProcessing $event) {
            $context = Tracer::extractContextFromPropagationHeaders($event->job->payload());

            $span = Tracer::build(sprintf('%s process', $event->job->resolveName()))
                ->setSpanKind(SpanKind::KIND_CONSUMER)
                ->setParent($context)
                ->startSpan();

            $span->setAttribute('messaging.system', config(sprintf('queue.connections.%s.driver', $event->connectionName)))
                ->setAttribute('messaging.operation', 'process')
                ->setAttribute('messaging.destination.kind', 'queue')
                ->setAttribute('messaging.destination.name', $event->job->getQueue())
                ->setAttribute('messaging.destination.template', $event->job->resolveName());

            Tracer::setRootSpan($span);

            Context::storage()->attach($span->storeInContext($context));
        });
    }

    protected function recordJobEnd(): void
    {
        app('events')->listen(JobProcessed::class, function (JobProcessed $event) {
            $scope = Tracer::activeScope();
            $span = Tracer::activeSpan();

            $scope?->detach();
            $span->end();
        });

        app('events')->listen(JobFailed::class, function (JobFailed $event) {
            $scope = Tracer::activeScope();
            $span = Tracer::activeSpan();

            $span->recordException($event->exception);

            $span->end();
            $scope->detach();
        });
    }

    protected function registerQueueInterceptor(QueueManager $queue): void
    {
        $queue->createPayloadUsing(fn () => Tracer::propagationHeaders());
    }

    protected function traceDispatchCalls(): void
    {
        hook(
            Dispatcher::class,
            'dispatch',
            pre: function (Dispatcher $dispatcher, array $params) {
                $job = $params[0];
                $jobClass = is_object($job) ? get_class($job) : null;

                $parentContext = Tracer::currentContext();

                $span = Tracer::build(trim(sprintf('%s publish', $jobClass ?? '')))
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->setParent($parentContext)
                    ->startSpan();

                try {
                    $connection = property_exists($job, 'connection') && $job->connection != null ? $job->connection : config('queue.default');
                    $queue = property_exists($job, 'queue') && $job->queue != null ? $job->queue : config(sprintf('queue.connections.%s.queue', $connection));

                    $span->setAttributes(array_filter([
                        TraceAttributes::MESSAGING_SYSTEM => config(sprintf('queue.connections.%s.driver', $connection)),
                        TraceAttributes::MESSAGING_OPERATION => 'publish',
                        'messaging.destination.name' => $queue,
                        'messaging.destination.template' => is_object($job) ? get_class($job) : null,
                    ]));
                } catch (\Throwable) {
                }

                Context::storage()->attach($span->storeInContext($parentContext));
            },
            post: function (Dispatcher $dispatcher, array $params, mixed $result, ?\Throwable $exception): mixed {
                $scope = Tracer::activeScope();
                $span = Tracer::activeSpan();

                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $scope?->detach();
                $span->end();

                return $result;
            }
        );
    }
}
