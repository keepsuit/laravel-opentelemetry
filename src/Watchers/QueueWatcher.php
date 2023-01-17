<?php

namespace Keepsuit\LaravelOpenTelemetry\Watchers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\QueueManager;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Trace\SpanKind;

class QueueWatcher extends Watcher
{
    use SpanTimeAdapter;

    protected array $startedSpans = [];

    public function register(Application $app): void
    {
        if ($app->resolved('queue')) {
            $this->registerQueueInterceptor($app['queue']);
        } else {
            $app->afterResolving('queue', fn ($queue) => $this->registerQueueInterceptor($queue));
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

            $scope = $span->activate();

            $this->startedSpans[$event->job->getJobId()] = [$span, $scope];
        });
    }

    protected function recordJobEnd(): void
    {
        app('events')->listen(JobProcessed::class, function (JobProcessed $event) {
            [$span, $scope] = $this->startedSpans[$event->job->getJobId()] ?? [null, null];

            $span?->end();
            $scope?->detach();

            unset($this->startedSpans[$event->job->getJobId()]);
        });
    }

    protected function registerQueueInterceptor(QueueManager $queue): void
    {
        $queue->createPayloadUsing(fn () => Tracer::activeSpanPropagationHeaders());
    }
}
