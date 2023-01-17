<?php

namespace Keepsuit\LaravelOpenTelemetry\Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Spatie\Valuestore\Valuestore;

class TestJob implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(protected Valuestore $valuestore)
    {
    }

    public function handle()
    {
        $this->valuestore->put('traceparentInJob', $this->job->payload()['traceparent'] ?? null);
        $this->valuestore->put('traceIdInJob', Tracer::traceId());
    }
}
