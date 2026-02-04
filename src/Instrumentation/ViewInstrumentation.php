<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Contracts\View\Engine;
use Illuminate\Contracts\View\View;
use Illuminate\View\Engines\EngineResolver;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\Support\InstrumentationUtilities;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\Support\View\TracedViewEngine;

class ViewInstrumentation implements Instrumentation
{
    use InstrumentationUtilities;

    public function register(array $options): void
    {
        $this->callAfterResolving('view.engine.resolver', $this->wrapViewEngines(...));
    }

    protected function wrapViewEngines(EngineResolver $engineResolver): void
    {
        /** @var string[] $resolvers */
        $resolvers = array_keys((new \ReflectionClass($engineResolver))->getProperty('resolvers')->getValue($engineResolver));

        foreach ($resolvers as $name) {
            try {
                $realEngine = $engineResolver->resolve($name);

                $engineResolver->register($name, fn () => $this->wrapViewEngine($name, $realEngine));
            } catch (\Throwable) {
            }
        }
    }

    protected function wrapViewEngine(string $name, Engine $realEngine): Engine
    {
        /** @var \Illuminate\Contracts\View\Factory $viewFactory */
        $viewFactory = app()->make('view');

        $viewFactory->composer('*', static function (View $view) use ($viewFactory): void {
            $viewFactory->share(TracedViewEngine::VIEW_NAME, $view->name());
        });

        return new TracedViewEngine($name, $realEngine, $viewFactory);
    }
}
