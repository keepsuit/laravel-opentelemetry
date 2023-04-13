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

//    public function recordCacheHit(CacheHit $event): void
//    {
//        $this->addEvent('cache hit', [
//            'key' => $event->key,
//            'tags' => json_encode($event->tags),
//        ]);
//    }
//
//    public function recordCacheMiss(CacheMissed $event): void
//    {
//        $this->addEvent('cache miss', [
//            'key' => $event->key,
//            'tags' => json_encode($event->tags),
//        ]);
//    }
//
//    /** @psalm-suppress UndefinedPropertyFetch */
//    public function recordCacheSet(KeyWritten $event): void
//    {
//        $ttl = property_exists($event, 'minutes')
//            ? $event->minutes * 60
//            : $event->seconds;
//
//        $this->addEvent('cache set', [
//            'key' => $event->key,
//            'tags' => json_encode($event->tags),
//            'expires_at' => $ttl > 0 ? now()->addSeconds($ttl)->getTimestamp() : 'never',
//            'expires_in_seconds' => $ttl > 0 ? $ttl : 'never',
//            'expires_in_human' => $ttl > 0 ? now()->addSeconds($ttl)->diffForHumans() : 'never',
//        ]);
//    }
//
//    public function recordCacheForget(KeyForgotten $event): void
//    {
//        $this->addEvent('cache forget', [
//            'key' => $event->key,
//            'tags' => json_encode($event->tags),
//        ]);
//    }
//
//    private function addEvent(string $name, iterable $attributes = []): void
//    {
//        Tracer::activeSpan()->addEvent($name, $attributes);
//    }
}
