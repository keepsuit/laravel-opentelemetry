<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Arr;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Symfony\Component\Console\Input\InputInterface;

class ConsoleInstrumentation implements Instrumentation
{
    protected \WeakMap $commands;

    protected array $excluded = [];

    public function register(array $options): void
    {
        $this->commands = new \WeakMap;
        $this->excluded = Arr::map($options['excluded'] ?? [], function (string $command) {
            if (class_exists($command)) {
                try {
                    return app($command)->getName();
                } catch (\Throwable) {
                    return null;
                }
            }

            return $command;
        });

        app('events')->listen(CommandStarting::class, [$this, 'commandStarting']);
        app('events')->listen(CommandFinished::class, [$this, 'commandFinished']);
    }

    public function commandStarting(CommandStarting $event): void
    {
        if (! $event->command) {
            return;
        }

        if (in_array($event->command, $this->excluded, true)) {
            return;
        }

        $span = Tracer::newSpan($event->command)
            ->setAttribute('console.command', $event->command)
            ->start();

        $this->recordCommandArguments($span, $event->input);

        $scope = $span->activate();

        $this->commands[$event->input] = [$span, $scope];
    }

    public function commandFinished(CommandFinished $event): void
    {
        $trace = $this->commands[$event->input] ?? null;

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
}
