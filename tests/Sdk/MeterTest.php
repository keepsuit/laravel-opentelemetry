<?php

it('can build open telemetry meter', function () {
    /** @var \OpenTelemetry\SDK\Metrics\Meter $meter */
    $meter = app(\OpenTelemetry\API\Metrics\MeterInterface::class);

    expect($meter)
        ->toBeInstanceOf(\OpenTelemetry\SDK\Metrics\Meter::class);
});
