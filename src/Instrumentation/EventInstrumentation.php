<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;

class EventInstrumentation implements Instrumentation
{
    /**
     * @var string[]
     */
    protected static array $excludedEvents = [];

    public function register(array $options): void
    {
        static::$excludedEvents = Arr::get($options, 'excluded', []);

        app('events')->listen('*', [$this, 'recordEvent']);
    }

    public function recordEvent(string $event, array $payload): void
    {
        if ($this->isInternalLaravelEvent($event) || $this->isExcludedEvent($event)) {
            return;
        }

        Tracer::activeSpan()->addEvent('event fired', [
            'event' => $event,
        ]);
    }

    protected function isInternalLaravelEvent(string $event): bool
    {
        return Str::is([
            'Illuminate\*',
            'Laravel\Octane\*',
            'Laravel\Scout\*',
            'Laravel\Horizon\*',
            'eloquent*',
            'bootstrapped*',
            'bootstrapping*',
            'creating*',
            'composing*',
        ], $event);
    }

    protected function isExcludedEvent(string $event): bool
    {
        return in_array($event, static::$excludedEvents);
    }
}
