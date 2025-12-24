<?php

namespace Keepsuit\LaravelOpenTelemetry\Support;

use Illuminate\Support\Str;

class ResourceAttributesParser
{
    /**
     * @return array<string, string>
     */
    public static function parse(?string $attributes): array
    {
        if ($attributes == null) {
            return [];
        }

        return Str::of($attributes)
            ->explode(',')
            ->mapWithKeys(function (string $value) {
                $parts = explode('=', $value, limit: 2);

                if (count($parts) !== 2) {
                    return [];
                }

                return [trim($parts[0]) => trim($parts[1])];
            })
            ->all();
    }
}
