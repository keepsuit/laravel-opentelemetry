<?php

namespace Keepsuit\LaravelOpenTelemetry\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Common\Time\SystemClock;

class CarbonClock implements ClockInterface
{
    protected SystemClock $systemClock;

    public function __construct()
    {
        $this->systemClock = new SystemClock;
    }

    public function now(): int
    {
        if (Carbon::hasTestNow()) {
            return static::carbonToNanos(CarbonImmutable::now());
        }

        return $this->systemClock->now();
    }

    public static function carbonToNanos(CarbonInterface $carbon): int
    {
        return (int) $carbon->getPreciseTimestamp(6) * 1000;
    }
}
