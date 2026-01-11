<?php

namespace Keepsuit\LaravelOpenTelemetry\Support;

use OpenTelemetry\API\Trace\SpanContextValidator;
use OpenTelemetry\API\Trace\StatusCode as ApiStatusCode;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;

final class TraceBuffer
{
    protected string $traceId;

    /** @var ReadableSpanInterface[] */
    protected array $spans = [];

    protected ?ReadableSpanInterface $root = null;

    protected ?int $firstEndEpochNanos = null;

    protected ?int $lastEndEpochNanos = null;

    public function __construct(string $traceId)
    {
        $this->traceId = $traceId;
    }

    public function addSpan(ReadableSpanInterface $span): void
    {
        $this->spans[] = $span;

        $spanData = $span->toSpanData();

        if ($this->root === null && ! SpanContextValidator::isValidSpanId($spanData->getParentSpanId())) {
            $this->root = $span;
        }

        $this->firstEndEpochNanos ??= $spanData->getEndEpochNanos();
        $this->lastEndEpochNanos = max($this->lastEndEpochNanos ?? 0, $spanData->getEndEpochNanos());
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
            $spanData = $span->toSpanData();

            if ($spanData->getStatus()->getCode() === ApiStatusCode::STATUS_ERROR) {
                return true;
            }
        }

        return false;
    }
}
