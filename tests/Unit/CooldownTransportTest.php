<?php

use Keepsuit\LaravelOpenTelemetry\Support\CooldownTransport;
use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\LoggerHolder;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use Psr\Log\AbstractLogger;
use Spatie\TestTime\TestTime;

beforeEach(function () {
    TestTime::freeze();

    $this->sdkLogger = new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $messages = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->messages[] = (string) $message;
        }
    };
    LoggerHolder::set($this->sdkLogger);
    // The SDK keeps the log writer it picked first.
    Logging::reset();

    $this->stateFile = sprintf('%s/laravel-opentelemetry-test-%s.pause', sys_get_temp_dir(), uniqid());

    $this->inner = new class implements TransportInterface
    {
        public int $calls = 0;

        public ?Throwable $error = null;

        public function contentType(): string
        {
            return 'application/x-protobuf';
        }

        public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
        {
            $this->calls++;

            return $this->error !== null ? new ErrorFuture($this->error) : new CompletedFuture(null);
        }

        public function shutdown(?CancellationInterface $cancellation = null): bool
        {
            return true;
        }

        public function forceFlush(?CancellationInterface $cancellation = null): bool
        {
            return true;
        }
    };
});

afterEach(function () {
    LoggerHolder::unset();
    Logging::reset();
    @unlink($this->stateFile);
});

function cooldownTransport(TransportInterface $inner, string $stateFile): CooldownTransport
{
    return new CooldownTransport($inner, 30, $stateFile);
}

it('drops exports during the cooldown after a failed export', function () {
    $this->inner->error = new RuntimeException('Export retry limit exceeded');
    $transport = cooldownTransport($this->inner, $this->stateFile);

    expect(fn () => $transport->send('payload')->await())->toThrow(RuntimeException::class);

    expect($transport->send('payload')->await())->toBeNull()
        ->and($this->inner->calls)->toBe(1)
        ->and($this->sdkLogger->messages)->toBe(['Export failed, exports are paused for 30 seconds']);

    TestTime::addSeconds(30);

    expect(fn () => $transport->send('payload')->await())->toThrow(RuntimeException::class)
        ->and($this->inner->calls)->toBe(2);
});

it('shares the cooldown with other processes through the state file', function () {
    $this->inner->error = new RuntimeException('Export retry limit exceeded');

    rescue(fn () => cooldownTransport($this->inner, $this->stateFile)->send('payload')->await(), report: false);

    $this->inner->error = null;
    $otherProcess = cooldownTransport($this->inner, $this->stateFile);

    expect($otherProcess->send('payload')->await())->toBeNull()
        ->and($this->inner->calls)->toBe(1);
});

it('keeps exporting when the collector rejects the payload', function () {
    $this->inner->error = new RuntimeException('Bad Request', 400);
    $transport = cooldownTransport($this->inner, $this->stateFile);

    rescue(fn () => $transport->send('payload')->await(), report: false);
    rescue(fn () => $transport->send('payload')->await(), report: false);

    expect($this->inner->calls)->toBe(2)
        ->and(file_exists($this->stateFile))->toBeFalse();
});

it('keeps exporting after successful exports', function () {
    $transport = cooldownTransport($this->inner, $this->stateFile);

    $transport->send('payload')->await();
    $transport->send('payload')->await();

    expect($this->inner->calls)->toBe(2);
});
