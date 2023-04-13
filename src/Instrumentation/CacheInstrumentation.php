<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;

class CacheInstrumentation implements Instrumentation
{
    public function register(array $options): void
    {
        app('events')->listen(CacheHit::class, [$this, 'recordCacheHit']);
        app('events')->listen(CacheMissed::class, [$this, 'recordCacheMiss']);

        app('events')->listen(KeyWritten::class, [$this, 'recordCacheSet']);
        app('events')->listen(KeyForgotten::class, [$this, 'recordCacheForget']);
    }

    public function recordCacheHit(CacheHit $event): void
    {
        $this->addEvent('cache hit', [
            'key' => $event->key,
            'tags' => json_encode($event->tags),
        ]);
    }

    public function recordCacheMiss(CacheMissed $event): void
    {
        $this->addEvent('cache miss', [
            'key' => $event->key,
            'tags' => json_encode($event->tags),
        ]);
    }

    /** @psalm-suppress UndefinedPropertyFetch */
    public function recordCacheSet(KeyWritten $event): void
    {
        $ttl = property_exists($event, 'minutes')
            ? $event->minutes * 60
            : $event->seconds;

        $this->addEvent('cache set', [
            'key' => $event->key,
            'tags' => json_encode($event->tags),
            'expires_at' => $ttl > 0 ? now()->addSeconds($ttl)->getTimestamp() : 'never',
            'expires_in_seconds' => $ttl > 0 ? $ttl : 'never',
            'expires_in_human' => $ttl > 0 ? now()->addSeconds($ttl)->diffForHumans() : 'never',
        ]);
    }

    public function recordCacheForget(KeyForgotten $event): void
    {
        $this->addEvent('cache forget', [
            'key' => $event->key,
            'tags' => json_encode($event->tags),
        ]);
    }

    private function addEvent(string $name, iterable $attributes = []): void
    {
        Tracer::activeSpan()->addEvent($name, $attributes);
    }
}
