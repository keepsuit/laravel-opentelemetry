<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;

class EventInstrumentation implements Instrumentation
{
    /**
     * @var string[]
     */
    protected static array $ignoredEvents = [];

    public function register(array $options): void
    {
        static::$ignoredEvents = Arr::get($options, 'ignored', []);

        app('events')->listen('*', [$this, 'recordEvent']);
    }

    public function recordEvent(string $event, array $payload): void
    {
        if ($this->isInternalLaravelEvent($event) || $this->isIgnoredEvent($event)) {
            return;
        }

        Tracer::activeSpan()->addEvent(sprintf('Event %s fired', $event), [
            'event.name' => $event,
        ]);
    }

    protected function isInternalLaravelEvent(string $event): bool
    {
        return Str::is([
            'Illuminate\*',
            'Laravel\Octane\*',
            'Laravel\Scout\*',
            'eloquent*',
            'bootstrapped*',
            'bootstrapping*',
            'creating*',
            'composing*',
        ], $event);
    }

    protected function isIgnoredEvent(string $event): bool
    {
        return in_array($event, static::$ignoredEvents);
    }
}
