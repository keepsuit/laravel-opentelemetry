<?php

namespace Keepsuit\LaravelOpenTelemetry\Support;

trait InstrumentationUtilities
{
    /**
     * @template  T of object
     *
     * @param  class-string<T>  $name
     * @param  \Closure(T): void  $callback
     */
    protected function callAfterResolving(string $name, \Closure $callback): void
    {
        if (app()->resolved($name)) {
            $callback(app()->make($name));
        } else {
            app()->afterResolving($name, $callback);
        }
    }
}
