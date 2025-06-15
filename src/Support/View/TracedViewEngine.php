<?php

namespace Keepsuit\LaravelOpenTelemetry\Support\View;

use Illuminate\Contracts\View\Engine;
use Illuminate\Contracts\View\Factory;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;

class TracedViewEngine implements Engine
{
    public const VIEW_NAME = '__otel_view_name';

    public function __construct(
        protected string $name,
        protected Engine $engine,
        protected Factory $viewFactory,
    ) {}

    public function get($path, array $data = [])
    {
        if (! Tracer::traceStarted()) {
            return $this->engine->get($path, $data);
        }

        return Tracer::newSpan('view render')
            ->setAttribute('template.name', $this->viewFactory->shared(self::VIEW_NAME, basename($path)))
            ->setAttribute('template.engine', $this->name)
            ->measure(fn () => $this->engine->get($path, $data));
    }
}
