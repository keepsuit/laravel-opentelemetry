<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Closure;
use Illuminate\Support\Arr;
use OpenTelemetry\Trace\Span;
use OpenTelemetry\Trace\Tracer as OpenTelemetryTracer;

class Tracer
{
    /** @var Span[] */
    protected array $startedSpans = [];

    public function __construct(protected OpenTelemetryTracer $tracer)
    {
    }

    public function start(string $name, ?Closure $onStart = null): self
    {
        $span = $this->tracer->startAndActivateSpan($name);

        $this->startedSpans[$name] = $span;

        if ($onStart) {
            $onStart($span);
        }

        return $this;
    }

    public function stop(string $name, ?Closure $onStop = null): self
    {
        if (Arr::has($this->startedSpans, $name)) {
            $span = $this->startedSpans[$name];

            if ($onStop) {
                $onStop($span);
            }

            $span->end();

            unset($this->startedSpans[$name]);
        }

        return $this;
    }

    public function measure(string $name, Closure $callback)
    {
        $span = $this->start($name);

        $result = $callback($span);

        $this->stop($name);

        return $result;
    }
}
