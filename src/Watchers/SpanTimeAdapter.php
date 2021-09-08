<?php

namespace Keepsuit\LaravelOpenTelemetry\Watchers;

use OpenTelemetry\Sdk\Trace\Clock;
use OpenTelemetry\Sdk\Trace\Span;

trait SpanTimeAdapter
{
    public function setSpanTimeMs(Span $span, float $timeMs): void
    {
        $MSEC_TO_NSEC = 1000000;

        $moment = Clock::get()->moment();
        $durationNs = (int)($timeMs * $MSEC_TO_NSEC);

        $span->setStartEpochTimestamp($moment[0] - $durationNs);
        $span->setStart($moment[1] - $durationNs);
    }
}
