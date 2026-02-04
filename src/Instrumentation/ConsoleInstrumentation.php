<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Collection;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;
use WeakMap;

class ConsoleInstrumentation implements Instrumentation
{
    /**
     * @var WeakMap<InputInterface, array{0: SpanInterface, 1: ScopeInterface}>
     */
    protected WeakMap $startedCommands;

    /**
     * @var string[]
     */
    protected array $whitelist = [];

    /**
     * @var string[]
     */
    protected array $whitelistWildcards = [];

    /**
     * @param  array{
     *     commands?: string[]
     * }  $options
     */
    public function register(array $options): void
    {
        $this->startedCommands = new WeakMap;

        /**
         * @var Collection<int, string> $wildcards
         * @var Collection<int, string> $fullCommands
         */
        [$wildcards, $fullCommands] = Collection::make($options['commands'] ?? [])
            ->map(function (string $command) {
                if (class_exists($command)) {
                    try {
                        return app($command)->getName();
                    } catch (Throwable) {
                        return null;
                    }
                }

                return $command;
            })
            ->partition(fn (string $command) => str_ends_with($command, '*'));

        $this->whitelist = $fullCommands->values()->all();
        $this->whitelistWildcards = $wildcards->values()->all();

        app('events')->listen(CommandStarting::class, [$this, 'commandStarting']);
        app('events')->listen(CommandFinished::class, [$this, 'commandFinished']);
    }

    public function commandStarting(CommandStarting $event): void
    {
        if (! $event->command) {
            return;
        }

        if (! $this->shouldRecord($event->command)) {
            return;
        }

        $span = Tracer::newSpan($event->command)
            ->setAttribute('console.command', $event->command)
            ->start();

        $this->recordCommandArguments($span, $event->input);

        $scope = $span->activate();

        $this->startedCommands[$event->input] = [$span, $scope];
    }

    public function commandFinished(CommandFinished $event): void
    {
        $trace = $this->startedCommands[$event->input] ?? null;

        if ($trace === null) {
            return;
        }

        /**
         * @var SpanInterface $span
         * @var ScopeInterface $scope
         */
        [$span, $scope] = $trace;

        Tracer::terminateActiveSpansUpToRoot($span);

        if ($event->exitCode !== 0) {
            $span->setStatus(StatusCode::STATUS_ERROR);
        } else {
            $span->setStatus(StatusCode::STATUS_OK);
        }

        $scope->detach();
        $span->end();
    }

    protected function recordCommandArguments(SpanInterface $span, InputInterface $input): void
    {
        foreach ($input->getArguments() as $key => $value) {
            if ($key === 'command') {
                continue;
            }

            $span->setAttribute('console.argument.'.$key, $value);
        }

        foreach ($input->getOptions() as $key => $value) {
            if ($value === false) {
                continue;
            }

            $span->setAttribute('console.option.'.$key, $value);
        }
    }

    protected function shouldRecord(string $command): bool
    {
        if (in_array($command, $this->whitelist, true)) {
            return true;
        }

        foreach ($this->whitelistWildcards as $wildcard) {
            if (str_starts_with($command, rtrim($wildcard, '*'))) {

                return true;
            }
        }

        return false;
    }
}
