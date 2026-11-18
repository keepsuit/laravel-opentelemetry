<?php

use Keepsuit\LaravelOpenTelemetry\Instrumentation\ReverbInstrumentation;
use Laravel\Reverb\Application;
use Laravel\Reverb\ConfigApplicationProvider;
use Laravel\Reverb\Contracts\ApplicationProvider;
use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Events\MessageSent;
use Laravel\Reverb\Protocols\Pusher\ConnectionRegistry;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;

beforeEach(function () {
    $this->originalServer = $_SERVER;

    app()->singleton(ConnectionRegistry::class);
    app()->instance(ApplicationProvider::class, new ConfigApplicationProvider(collect([
        ['app_id' => 'app-1', 'key' => 'key', 'secret' => 'secret', 'ping_interval' => 60, 'allowed_origins' => ['*'], 'max_message_size' => 10_000],
    ])));
});

afterEach(function () {
    $_SERVER = $this->originalServer;
});

function runReverbStart(): void
{
    $_SERVER['APP_RUNNING_IN_CONSOLE'] = '1';
    $_SERVER['argv'] = ['artisan', 'reverb:start'];
}

function fakeReverbConnection(string $id): Connection
{
    return new class(app(ApplicationProvider::class)->findById('app-1'), $id) extends Connection
    {
        public function __construct(protected Application $application, protected string $socketId) {}

        public function identifier(): string
        {
            return $this->socketId;
        }

        public function id(): string
        {
            return $this->socketId;
        }

        public function send(string $message): void {}

        public function control(string $type = 'ping'): void {}

        public function terminate(): void {}
    };
}

test('counts received and sent messages', function () {
    runReverbStart();
    registerInstrumentation(ReverbInstrumentation::class);

    $connection = fakeReverbConnection('1.1');
    MessageReceived::dispatch($connection, '{}');
    MessageSent::dispatch($connection, '{}');
    MessageSent::dispatch($connection, '{}');

    $metric = getRecordedMetrics()->firstWhere('name', 'laravel.reverb.messages');

    expect($metric)
        ->toBeInstanceOf(Metric::class)
        ->unit->toBe('{message}')
        ->data->toBeInstanceOf(Sum::class);

    $values = collect($metric->data->dataPoints)
        ->mapWithKeys(fn (NumberDataPoint $point) => [$point->attributes->get('network.io.direction') => $point->value]);

    expect($values->all())->toBe(['receive' => 1, 'transmit' => 2])
        ->and($metric->data->dataPoints[0]->attributes->get('laravel.reverb.app.id'))->toBe('app-1');
});

test('observes active connections', function () {
    runReverbStart();
    registerInstrumentation(ReverbInstrumentation::class);

    $registry = app(ConnectionRegistry::class);
    $registry->add(fakeReverbConnection('1.1'));
    $registry->add($connection = fakeReverbConnection('1.2'));

    $metric = getRecordedMetrics()->firstWhere('name', 'laravel.reverb.active_connections');

    expect($metric)
        ->toBeInstanceOf(Metric::class)
        ->unit->toBe('{connection}')
        ->data->toBeInstanceOf(Gauge::class)
        ->data->dataPoints->{0}->value->toBe(2)
        ->data->dataPoints->{0}->attributes->get('laravel.reverb.app.id')->toBe('app-1');

    $registry->remove($connection);

    $metric = getRecordedMetrics()->where('name', 'laravel.reverb.active_connections')->last();

    expect($metric->data->dataPoints[0]->value)->toBe(1);
});

test('does not record metrics outside reverb:start', function () {
    registerInstrumentation(ReverbInstrumentation::class);

    app(ConnectionRegistry::class)->add($connection = fakeReverbConnection('1.1'));
    MessageReceived::dispatch($connection, '{}');
    MessageSent::dispatch($connection, '{}');

    expect(getRecordedMetrics()->filter(fn (Metric $metric) => str_starts_with($metric->name, 'laravel.reverb.')))->toBeEmpty();
});
