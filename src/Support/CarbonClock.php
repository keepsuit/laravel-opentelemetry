<?php

namespace Keepsuit\LaravelOpenTelemetry\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use OpenTelemetry\SDK\Common\Time\ClockInterface;
use OpenTelemetry\SDK\Common\Time\SystemClock;

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
            return (int) CarbonImmutable::now()->getPreciseTimestamp(6) * 1000;
        }

        return $this->systemClock->now();
    }

    public function nanoTime(): int
    {
        return $this->now();
    }
}
