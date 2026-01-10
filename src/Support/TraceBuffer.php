<?php

namespace Keepsuit\LaravelOpenTelemetry\Support;

use OpenTelemetry\API\Trace\StatusCode as ApiStatusCode;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;

final class TraceBuffer
{
    private string $traceId;

    /** @var ReadableSpanInterface[] */
    private array $spans = [];

    private ?ReadableSpanInterface $root = null;

    private ?int $firstEndEpochNanos = null;

    private ?int $lastEndEpochNanos = null;

    public function __construct(string $traceId)
    {
        $this->traceId = $traceId;
    }

    public function addSpan(ReadableSpanInterface $span): void
    {
        $this->spans[] = $span;

        // Set root span if this span has no parent or parent span id is empty
        try {
            $spanData = $span->toSpanData();
            $parentSpanId = $spanData->getParentSpanId();
        } catch (\Throwable $e) {
            $parentSpanId = '';
        }

        if ($this->root === null && $parentSpanId === '') {
            $this->root = $span;
        }

        // Use SpanData end epoch nanos for accurate durations
        try {
            $end = $span->toSpanData()->getEndEpochNanos();
        } catch (\Throwable $e) {
            $end = null;
        }

        if ($end !== null) {
            if ($this->firstEndEpochNanos === null) {
                $this->firstEndEpochNanos = $end;
            }

            $this->lastEndEpochNanos = max($this->lastEndEpochNanos ?? 0, $end);
        }
    }

    /** @return ReadableSpanInterface[] */
    public function getSpans(): array
    {
        return $this->spans;
    }

    public function getRootSpan(): ?ReadableSpanInterface
    {
        return $this->root;
    }

    public function getDurationMs(): int
    {
        if ($this->firstEndEpochNanos === null || $this->lastEndEpochNanos === null) {
            return 0;
        }

        $durationNs = $this->lastEndEpochNanos - $this->firstEndEpochNanos;

        return (int) floor($durationNs / 1_000_000);
    }

    public function hasError(): bool
    {
        foreach ($this->spans as $span) {
            try {
                $spanData = $span->toSpanData();
                $status = $spanData->getStatus();

                if ($status && $status->getCode() !== ApiStatusCode::STATUS_OK) {
                    return true;
                }
            } catch (\Throwable $e) {
                // ignore and continue
            }

            try {
                if ($span->getAttribute('exception') !== null) {
                    return true;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return false;
    }

    public function getTraceId(): string
    {
        return $this->traceId;
    }

    public function getLastEndEpochNanos(): ?int
    {
        return $this->lastEndEpochNanos;
    }
}
