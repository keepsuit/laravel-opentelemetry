<?php

namespace Keepsuit\LaravelOpenTelemetry\Watchers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\QueueManager;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

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
            /** @var ContextInterface $context */
            $context = app(TextMapPropagatorInterface::class)->extract($event->job->payload());

            $span = Tracer::build($event->job->resolveName(), SpanKind::KIND_CONSUMER)
                ->setParent($context)
                ->startSpan();

            $span->activate();

            $this->startedSpans[$event->job->getJobId()] = $span;
        });
    }

    protected function recordJobEnd(): void
    {
        app('events')->listen(JobProcessed::class, function (JobProcessed $event) {
            $span = $this->startedSpans[$event->job->getJobId()] ?? null;

            $span?->end();
        });
    }

    protected function registerQueueInterceptor(QueueManager $queue): void
    {
        $queue->createPayloadUsing(fn () => Tracer::activeSpanPropagationHeaders());
    }
}
