<?php

namespace Keepsuit\LaravelOpenTelemetry\Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Spatie\Valuestore\Valuestore;

class TestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected Valuestore $valuestore)
    {
    }

    public function handle(): void
    {
        $this->valuestore->put('traceparentInJob', $this->job->payload()['traceparent'] ?? null);
        $this->valuestore->put('traceIdInJob', Tracer::traceId());
        $this->valuestore->put('logContextInJob', Log::sharedContext());
    }
}
