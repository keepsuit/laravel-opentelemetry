<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\QueueManager;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Trace\SpanKind;

class QueueInstrumentation implements Instrumentation
{
    use SpanTimeAdapter;

    public function register(array $options): void
    {
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

            $span = Tracer::newSpan(sprintf('%s process', $event->job->resolveName()))
                ->setSpanKind(SpanKind::KIND_CONSUMER)
                ->setParent($context)
                ->setAttribute('messaging.system', config(sprintf('queue.connections.%s.driver', $event->connectionName)))
                ->setAttribute('messaging.operation', 'process')
                ->setAttribute('messaging.destination.kind', 'queue')
                ->setAttribute('messaging.destination.name', $event->job->getQueue())
                ->setAttribute('messaging.destination.template', $event->job->resolveName())
                ->start();

            $span->activate();

            Tracer::updateLogContext();
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

            $scope?->detach();
            $span->end();
        });
    }

    protected function registerQueueInterceptor(QueueManager $queue): void
    {
        $queue->createPayloadUsing(fn () => Tracer::propagationHeaders());
    }
}
