<?php

namespace Keepsuit\LaravelOpenTelemetry\Watchers;

use Illuminate\Contracts\Foundation\Application;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Nuwave\Lighthouse\Events\EndExecution;
use Nuwave\Lighthouse\Events\EndRequest;
use Nuwave\Lighthouse\Events\StartExecution;
use Nuwave\Lighthouse\Events\StartOperationOrOperations;
use Nuwave\Lighthouse\Events\StartRequest;
use OpenTelemetry\API\Trace\SpanInterface;

class LighthouseWatcher extends Watcher
{
    use SpanTimeAdapter;

    protected ?SpanInterface $requestSpan;
    protected ?SpanInterface $parseSpan;
    protected ?SpanInterface $validateSpan;
    protected ?SpanInterface $executeSpan;

    public function register(Application $app): void
    {
        if (! class_exists(StartRequest::class)) {
            return;
        }

        $app['events']->listen(StartRequest::class, [$this, 'recordStartRequest']);
        $app['events']->listen(StartOperationOrOperations::class, [$this, 'recordStartOperation']);
        $app['events']->listen(StartExecution::class, [$this, 'recordStartExecution']);
        $app['events']->listen(EndExecution::class, [$this, 'recordEndExecution']);
        $app['events']->listen(EndRequest::class, [$this, 'recordEndRequest']);
    }

    public function recordStartRequest(): void
    {
        $this->requestSpan = Tracer::start('graphql.request');
        $this->requestSpan->activate();

        $this->parseSpan = Tracer::start('graphql.parse');
        $this->parseSpan->activate();
    }

    public function recordStartOperation(): void
    {
        $this->parseSpan?->end();
        $this->parseSpan = null;

        $this->validateSpan = Tracer::start('graphql.validate');
        $this->validateSpan->activate();
    }

    public function recordStartExecution(): void
    {
        $this->validateSpan?->end();
        $this->validateSpan = null;

        $this->executeSpan = Tracer::start('graphql.execute');
        $this->executeSpan->activate();
    }

    public function recordEndExecution(): void
    {
        $this->executeSpan?->end();
        $this->executeSpan = null;
    }

    public function recordEndRequest(): void
    {
        $this->requestSpan?->end();
        $this->requestSpan = null;
    }
}
