<?php

namespace Keepsuit\LaravelOpenTelemetry\Commands;

use Illuminate\Console\Command;

class LaravelOpenTelemetryCommand extends Command
{
    public $signature = 'laravel-opentelemetry';

    public $description = 'My command';

    public function handle()
    {
        $this->comment('All done');
    }
}
