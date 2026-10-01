<?php

namespace Keepsuit\LaravelOpenTelemetry\TailSampling;

use Carbon\CarbonImmutable;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Trace\SpanContextValidator;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;

final class TraceBuffer
{
    protected string $traceId;

    /** @var ReadableSpanInterface[] */
    protected array $spans = [];

    protected ?ReadableSpanInterface $root = null;

    protected ?int $bufferCreatedMs = null;

    protected ?int $traceStartedMs = null;

    protected ?int $traceEndedMs = null;

    public function __construct(string $traceId)
    {
        $this->traceId = $traceId;
    }

    public function addSpan(ReadableSpanInterface $span): void
    {
        $this->bufferCreatedMs ??= CarbonImmutable::now()->getTimestampMs();

        $this->spans[] = $span;

        $spanData = $span->toSpanData();

        if ($this->root === null && $this->isLocalRoot($spanData)) {
            $this->root = $span;
        }

        $this->traceStartedMs = min($this->traceStartedMs ?? PHP_INT_MAX, (int) ($spanData->getStartEpochNanos() / ClockInterface::NANOS_PER_MILLISECOND));
        $this->traceEndedMs = max($this->traceEndedMs ?? 0, (int) ($spanData->getEndEpochNanos() / ClockInterface::NANOS_PER_MILLISECOND));
    }

    public function getTraceId(): string
    {
        return $this->traceId;
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

    public function getDecisionDurationMs(): int
    {
        if ($this->bufferCreatedMs === null) {
            return 0;
        }

        return CarbonImmutable::now()->getTimestampMs() - $this->bufferCreatedMs;
    }

    public function getTraceDurationMs(): int
    {
        if ($this->traceStartedMs === null || $this->traceEndedMs === null) {
            return 0;
        }

        return $this->traceEndedMs - $this->traceStartedMs;
    }

    /**
     * A span is the local root when it has no parent, or when its parent comes from
     * another process (for example a propagated `traceparent` header).
     * A remote parent that is already in this buffer belongs to the same process
     * (for example a job dispatched on the sync queue), so the trace is not complete yet.
     */
    protected function isLocalRoot(SpanDataInterface $spanData): bool
    {
        if (! SpanContextValidator::isValidSpanId($spanData->getParentSpanId())) {
            return true;
        }

        return $spanData->getParentContext()->isRemote() && ! $this->hasSpan($spanData->getParentSpanId());
    }

    protected function hasSpan(string $spanId): bool
    {
        foreach ($this->spans as $span) {
            if ($span->getContext()->getSpanId() === $spanId) {
                return true;
            }
        }

        return false;
    }
}
