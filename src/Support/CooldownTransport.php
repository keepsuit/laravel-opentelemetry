<?php

namespace Keepsuit\LaravelOpenTelemetry\Support;

use Carbon\Carbon;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use Safe\Exceptions\FilesystemException;
use Throwable;

use function Safe\filemtime;
use function Safe\touch;

/**
 * Drops exports for a cooldown after a failed one, so an unavailable collector costs one failed attempt per cooldown
 * instead of one per export.
 *
 * @template CONTENT_TYPE of string
 *
 * @implements TransportInterface<CONTENT_TYPE>
 */
final class CooldownTransport implements TransportInterface
{
    use LogsMessagesTrait;

    private int $pausedUntil = 0;

    /**
     * @param  TransportInterface<CONTENT_TYPE>  $transport
     * @param  string  $stateFile  Shared by every process that exports to the same collector: a short-lived process
     *                             (a request under PHP-FPM, an Artisan command) would otherwise pay its own failure.
     */
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly int $cooldownSeconds,
        private readonly string $stateFile,
    ) {}

    public function contentType(): string
    {
        return $this->transport->contentType();
    }

    /**
     * @return FutureInterface<?string>
     */
    public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
    {
        if ($this->isPaused()) {
            // A null response is an export without a response body: exporters count it as a success and log nothing.
            return new CompletedFuture(null);
        }

        return $this->transport->send($payload, $cancellation)->catch(function (Throwable $e) {
            if (! $this->isRejection($e)) {
                $this->pause();
            }

            throw $e;
        });
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->transport->shutdown($cancellation);
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->transport->forceFlush($cancellation);
    }

    private function isPaused(): bool
    {
        $now = Carbon::now()->getTimestamp();

        if ($this->pausedUntil > $now) {
            return true;
        }

        clearstatcache(true, $this->stateFile);

        if (! is_file($this->stateFile)) {
            return false;
        }

        try {
            // The pause expiry is stored as the file modification time: a stat call, no read.
            $pausedUntil = filemtime($this->stateFile);
        } catch (FilesystemException) {
            return false;
        }

        if ($pausedUntil <= $now) {
            return false;
        }

        $this->pausedUntil = $pausedUntil;

        return true;
    }

    private function pause(): void
    {
        $this->pausedUntil = Carbon::now()->getTimestamp() + $this->cooldownSeconds;

        try {
            touch($this->stateFile, $this->pausedUntil);
        } catch (FilesystemException) {
            // Without a writable temp dir the pause applies to this process only.
        }

        self::logWarning(sprintf('Export failed, exports are paused for %d seconds', $this->cooldownSeconds));
    }

    /**
     * The collector answered with a non-retryable 4xx: it is reachable, the payload was rejected.
     */
    private function isRejection(Throwable $e): bool
    {
        return $e->getCode() >= 400 && $e->getCode() < 500;
    }
}
