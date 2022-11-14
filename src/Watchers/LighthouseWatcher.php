<?php

namespace Keepsuit\LaravelOpenTelemetry\Watchers;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Nuwave\Lighthouse\Events\EndExecution;
use Nuwave\Lighthouse\Events\EndRequest;
use Nuwave\Lighthouse\Events\StartExecution;
use Nuwave\Lighthouse\Events\StartOperationOrOperations;
use Nuwave\Lighthouse\Events\StartRequest;

class LighthouseWatcher extends Watcher
{
    use SpanTimeAdapter;

    protected ?Closure $endRequestSpan = null;

    protected ?Closure $endParseSpan = null;

    protected ?Closure $endValidateSpan = null;

    protected ?Closure $endExecuteSpan = null;

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
        $requestSpan = Tracer::start('graphql.request');
        $requestScope = $requestSpan->activate();

        $parseSpan = Tracer::start('graphql.parse');
        $parseScope = $parseSpan->activate();

        $this->endRequestSpan = function () use ($requestScope, $requestSpan) {
            $requestSpan->end();
            $requestScope->detach();
            $this->endRequestSpan = null;
        };

        $this->endParseSpan = function () use ($parseScope, $parseSpan) {
            $parseSpan->end();
            $parseScope->detach();
            $this->endParseSpan = null;
        };
    }

    public function recordStartOperation(): void
    {
        $this->endParseSpan?->__invoke();

        $validateSpan = Tracer::start('graphql.validate');
        $validateScope = $validateSpan->activate();

        $this->endValidateSpan = function () use ($validateScope, $validateSpan) {
            $validateSpan->end();
            $validateScope->detach();
            $this->endValidateSpan = null;
        };
    }

    public function recordStartExecution(): void
    {
        $this->endValidateSpan?->__invoke();

        $executeSpan = Tracer::start('graphql.execute');
        $executeScope = $executeSpan->activate();

        $this->endExecuteSpan = function () use ($executeScope, $executeSpan) {
            $executeSpan->end();
            $executeScope->detach();
            $this->endExecuteSpan = null;
        };
    }

    public function recordEndExecution(): void
    {
        $this->endExecuteSpan?->__invoke();
    }

    public function recordEndRequest(): void
    {
        $this->endRequestSpan?->__invoke();
    }
}
