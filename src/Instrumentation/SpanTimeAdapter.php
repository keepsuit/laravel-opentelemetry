<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Common\Time\ClockInterface;

trait SpanTimeAdapter
{
    protected function getEventStartTimestampNs(float $timeMs): int
    {
        $nowNs = ClockFactory::getDefault()->now();
        $durationNs = (int) ($timeMs * ClockInterface::NANOS_PER_MILLISECOND);

        return $nowNs - $durationNs;
    }
}
