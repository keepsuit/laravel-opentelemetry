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
use function Safe\unlink;

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
        $now = Carbon::now()->getTimestamp();

        if ($this->pausedUntil > $now) {
            return $this->skipped();
        }

        $sharedPausedUntil = $this->readSharedPause();

        if ($sharedPausedUntil !== null && $sharedPausedUntil > $now) {
            $this->pausedUntil = $sharedPausedUntil;

            return $this->skipped();
        }

        // An expired pause: this process checks whether the collector is back, the others keep skipping meanwhile.
        $probing = $sharedPausedUntil !== null;

        if ($probing) {
            $this->writeSharedPause($now + $this->cooldownSeconds);
        }

        return $this->transport->send($payload, $cancellation)
            ->map(function (?string $response) use ($probing) {
                if ($probing) {
                    $this->resume();
                }

                return $response;
            })
            ->catch(function (Throwable $e) use ($probing) {
                if (! $this->isRejection($e)) {
                    $this->pause();
                } elseif ($probing) {
                    $this->resume();
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

    /**
     * A null response is an export without a response body: exporters count it as a success and log nothing.
     *
     * @return FutureInterface<?string>
     */
    private function skipped(): FutureInterface
    {
        return new CompletedFuture(null);
    }

    private function pause(): void
    {
        $this->pausedUntil = Carbon::now()->getTimestamp() + $this->cooldownSeconds;
        $this->writeSharedPause($this->pausedUntil);

        self::logWarning(sprintf('Export failed, exports are paused for %d seconds', $this->cooldownSeconds));
    }

    private function resume(): void
    {
        $this->pausedUntil = 0;

        try {
            unlink($this->stateFile);
        } catch (FilesystemException) {
        }
    }

    /**
     * The pause expiry is stored as the state file modification time: a stat call, no read.
     */
    private function readSharedPause(): ?int
    {
        clearstatcache(true, $this->stateFile);

        if (! is_file($this->stateFile)) {
            return null;
        }

        try {
            return filemtime($this->stateFile);
        } catch (FilesystemException) {
            return null;
        }
    }

    private function writeSharedPause(int $until): void
    {
        try {
            touch($this->stateFile, $until);
        } catch (FilesystemException) {
            // Without a writable temp dir the pause applies to this process only.
        }
    }

    /**
     * The collector answered with a non-retryable 4xx: it is reachable, the payload was rejected.
     */
    private function isRejection(Throwable $e): bool
    {
        return $e->getCode() >= 400 && $e->getCode() < 500;
    }
}
