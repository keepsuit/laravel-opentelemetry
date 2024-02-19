<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\TraceAttributes;

class QueueInstrumentation implements Instrumentation
{
    use SpanTimeAdapter;

    /**
     * @var array<string,SpanInterface>
     */
    protected array $activeSpans = [];

    public function register(array $options): void
    {
        $this->recordJobQueueing();
        $this->recordJobProcessing();
    }

    protected function recordJobQueueing(): void
    {
        if (app()->resolved('queue')) {
            $this->registerQueueInterceptor(app('queue'));
        } else {
            app()->afterResolving('queue', fn ($queue) => $this->registerQueueInterceptor($queue));
        }

        app('events')->listen(JobQueued::class, function (JobQueued $event) {
            $uuid = $event->payload()['uuid'] ?? null;

            if (! is_string($uuid)) {
                return;
            }

            $span = $this->activeSpans[$uuid] ?? null;

            $span?->end();

            unset($this->activeSpans[$uuid]);
        });
    }

    protected function registerQueueInterceptor(QueueManager $queue): void
    {
        $queue->createPayloadUsing(function (string $connection, ?string $queue, array $payload) {
            $uuid = $payload['uuid'];

            if (! is_string($uuid)) {
                return $payload;
            }

            $jobName = Arr::get($payload, 'displayName', 'unknown');
            $queueName = Str::after($queue ?? 'default', 'queues:');

            $span = Tracer::newSpan(sprintf('%s enqueue', $jobName))
                ->setSpanKind(SpanKind::KIND_PRODUCER)
                ->setAttribute(TraceAttributes::MESSAGING_SYSTEM, $this->connectionDriver($connection))
                ->setAttribute(TraceAttributes::MESSAGING_OPERATION, 'enqueue')
                ->setAttribute(TraceAttributes::MESSAGE_ID, $uuid)
                ->setAttribute(TraceAttributes::MESSAGING_DESTINATION_NAME, $queueName)
                ->setAttribute(TraceAttributes::MESSAGING_DESTINATION_TEMPLATE, $jobName)
                ->start();

            $context = $span->storeInContext(Tracer::currentContext());

            $this->activeSpans[$uuid] = $span;

            return Tracer::propagationHeaders($context);
        });
    }

    protected function recordJobProcessing(): void
    {
        app('events')->listen(JobProcessing::class, function (JobProcessing $event) {
            $context = Tracer::extractContextFromPropagationHeaders($event->job->payload());

            $span = Tracer::newSpan(sprintf('%s process', $event->job->resolveName()))
                ->setSpanKind(SpanKind::KIND_CONSUMER)
                ->setParent($context)
                ->setAttribute(TraceAttributes::MESSAGING_SYSTEM, $this->connectionDriver($event->connectionName))
                ->setAttribute(TraceAttributes::MESSAGING_OPERATION, 'process')
                ->setAttribute(TraceAttributes::MESSAGE_ID, $event->job->uuid())
                ->setAttribute(TraceAttributes::MESSAGING_DESTINATION_NAME, $event->job->getQueue())
                ->setAttribute(TraceAttributes::MESSAGING_DESTINATION_TEMPLATE, $event->job->resolveName())
                ->start();

            $span->activate();

            Tracer::updateLogContext();
        });

        app('events')->listen(JobProcessed::class, function (JobProcessed $event) {
            $scope = Tracer::activeScope();
            $span = Tracer::activeSpan();

            $scope?->detach();
            $span->end();
        });

        app('events')->listen(JobFailed::class, function (JobFailed $event) {
            $scope = Tracer::activeScope();
            $span = Tracer::activeSpan();

            $span->recordException($event->exception)
                ->setStatus(StatusCode::STATUS_ERROR);

            $scope?->detach();
            $span->end();
        });
    }

    protected function connectionDriver(string $connection): string
    {
        return config(sprintf('queue.connections.%s.driver', $connection), 'unknown');
    }
}
