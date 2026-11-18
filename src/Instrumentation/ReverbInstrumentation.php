<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Keepsuit\LaravelOpenTelemetry\Facades\Meter;
use Laravel\Reverb\Contracts\ApplicationProvider;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Events\MessageSent;
use Laravel\Reverb\Protocols\Pusher\ConnectionRegistry;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\SemConv\Incubating\Attributes\NetworkIncubatingAttributes;
use Throwable;

class ReverbInstrumentation implements Instrumentation
{
    public function register(array $options): void
    {
        if (! class_exists(MessageSent::class) || ! app()->runningConsoleCommand('reverb:start')) {
            return;
        }

        $messages = Meter::counter('laravel.reverb.messages', '{message}', 'Number of WebSocket messages received and sent.');

        // MessageReceived is dispatched inside Reverb's message handling: a throwing listener would send `pusher:error` to the client.
        $countMessage = static fn (string $direction) => static function (MessageReceived|MessageSent $event) use ($messages, $direction) {
            try {
                $messages->add(1, [
                    'laravel.reverb.app.id' => $event->connection->app()->id(),
                    NetworkIncubatingAttributes::NETWORK_IO_DIRECTION => $direction,
                ]);
            } catch (Throwable $e) {
                report($e);
            }
        };

        app('events')->listen(MessageReceived::class, $countMessage(NetworkIncubatingAttributes::NETWORK_IO_DIRECTION_VALUE_RECEIVE));
        app('events')->listen(MessageSent::class, $countMessage(NetworkIncubatingAttributes::NETWORK_IO_DIRECTION_VALUE_TRANSMIT));

        // ConnectionRegistry exists since Reverb 1.14.
        if (! class_exists(ConnectionRegistry::class)) {
            return;
        }

        $registry = app(ConnectionRegistry::class);
        $applications = app(ApplicationProvider::class);

        // Static: the SDK holds callbacks bound to an object weakly, and this instance is not retained.
        Meter::observableGauge('laravel.reverb.active_connections', '{connection}', 'Number of open WebSocket connections.')
            ->observe(static function (ObserverInterface $observer) use ($registry, $applications) {
                foreach ($applications->all() as $application) {
                    $observer->observe($registry->count($application), ['laravel.reverb.app.id' => $application->id()]);
                }
            });
    }
}
