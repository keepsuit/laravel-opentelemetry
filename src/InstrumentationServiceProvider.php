<?php

namespace Keepsuit\LaravelOpenTelemetry;

use Illuminate\Support\ServiceProvider;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\Instrumentation;

class InstrumentationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (config('opentelemetry.instrumentation') as $key => $options) {
            if ($options === false) {
                continue;
            }

            if (is_array($options) && ! ($options['enabled'] ?? true)) {
                continue;
            }

            $instrumentation = $this->app->make($key);

            if ($instrumentation instanceof Instrumentation) {
                $instrumentation->register(is_array($options) ? $options : []);
            }
        }
    }
}
