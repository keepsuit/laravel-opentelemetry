<?php

namespace Keepsuit\LaravelOpenTelemetry\Watchers;

use OpenTelemetry\SDK\AbstractClock;

trait SpanTimeAdapter
{
    protected function getEventStartTimestampNs(float $timeMs): int
    {
        $MSEC_TO_NSEC = 1000000;

        $nowNs = AbstractClock::getDefault()->now();
        $durationNs = (int)($timeMs * $MSEC_TO_NSEC);

        return $nowNs - $durationNs;
    }
}
