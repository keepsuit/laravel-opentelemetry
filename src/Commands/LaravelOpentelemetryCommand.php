<?php

namespace Keepsuit\LaravelOpentelemetry\Commands;

use Illuminate\Console\Command;

class LaravelOpentelemetryCommand extends Command
{
    public $signature = 'laravel-opentelemetry';

    public $description = 'My command';

    public function handle()
    {
        $this->comment('All done');
    }
}
