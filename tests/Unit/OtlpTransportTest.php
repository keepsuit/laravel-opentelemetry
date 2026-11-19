<?php

use Keepsuit\LaravelOpenTelemetry\LaravelOpenTelemetryServiceProvider;
use OpenTelemetry\API\Signals;

it('fails within the connect timeout when the collector is unreachable', function (string $protocol) {
    $transport = invade(new LaravelOpenTelemetryServiceProvider(app()))->buildOtlpTransport([
        // Documentation address, never routed: the connection hangs until a timeout fires.
        'endpoint' => 'http://192.0.2.1:4318',
        'protocol' => $protocol,
        'traces_timeout' => 10000,
        'connect_timeout' => 200,
        'max_retries' => 0,
    ], Signals::TRACE);

    $start = hrtime(true);

    expect(fn () => $transport->send('payload')->await())->toThrow(Exception::class);
    expect((hrtime(true) - $start) / 1_000_000)->toBeLessThan(2000);
})->with(['http/protobuf', 'http/json']);
