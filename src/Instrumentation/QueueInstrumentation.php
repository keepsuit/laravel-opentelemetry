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

    protected array $startedSpans = [];

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

            $span = Tracer::build($event->job->resolveName())
                ->setSpanKind(SpanKind::KIND_CONSUMER)
                ->setParent($context)
                ->startSpan();

            Tracer::setRootSpan($span);

            $scope = $span->activate();

            $this->startedSpans[$event->job->getJobId()] = [$span, $scope];
        });
    }

    protected function recordJobEnd(): void
    {
        app('events')->listen(JobProcessed::class, function (JobProcessed $event) {
            [$span, $scope] = $this->startedSpans[$event->job->getJobId()] ?? [null, null];

            $scope?->detach();
            $span?->end();

            unset($this->startedSpans[$event->job->getJobId()]);
        });

        app('events')->listen(JobFailed::class, function (JobFailed $event) {
            [$span, $scope] = $this->startedSpans[$event->job->getJobId()] ?? [null, null];

            $span?->end();
            $scope?->detach();

            unset($this->startedSpans[$event->job->getJobId()]);
        });
    }

    protected function registerQueueInterceptor(QueueManager $queue): void
    {
        $queue->createPayloadUsing(fn () => Tracer::propagationHeaders());
    }
}
