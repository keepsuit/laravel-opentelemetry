<?php

namespace Keepsuit\LaravelOpenTelemetry\Support;

use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;

/**
 * Leaves out metrics without data points. With Delta temporality the SDK exports every synchronous instrument
 * on each collection, also when nothing was recorded since the last one, and Prometheus rejects the whole
 * OTLP request when one metric has no data points.
 */
final class NonEmptyMetricExporter implements AggregationTemporalitySelectorInterface, PushMetricExporterInterface
{
    public function __construct(
        private readonly MetricExporterInterface&AggregationTemporalitySelectorInterface $exporter,
    ) {}

    public function temporality(MetricMetadataInterface $metric)
    {
        return $this->exporter->temporality($metric);
    }

    /**
     * @param  iterable<int, Metric>  $batch
     */
    public function export(iterable $batch): bool
    {
        $metrics = [];

        foreach ($batch as $metric) {
            if (! $this->isEmpty($metric)) {
                $metrics[] = $metric;
            }
        }

        if ($metrics === []) {
            return true;
        }

        return $this->exporter->export($metrics);
    }

    public function forceFlush(): bool
    {
        return $this->exporter instanceof PushMetricExporterInterface ? $this->exporter->forceFlush() : true;
    }

    public function shutdown(): bool
    {
        return $this->exporter->shutdown();
    }

    private function isEmpty(Metric $metric): bool
    {
        $data = $metric->data;

        if (! $data instanceof Sum && ! $data instanceof Gauge && ! $data instanceof Histogram) {
            return false;
        }

        return is_array($data->dataPoints) && $data->dataPoints === [];
    }
}
