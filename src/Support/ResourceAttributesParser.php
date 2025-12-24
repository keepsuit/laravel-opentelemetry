<?php

namespace Keepsuit\LaravelOpenTelemetry\Support;

use OpenTelemetry\SDK\Common\Configuration\Parser\MapParser;

class ResourceAttributesParser
{
    /**
     * @return array<string, string>
     */
    public static function parse(?string $attributes): array
    {
        if ($attributes === null || $attributes === '') {
            return [];
        }

        return array_map('urldecode', MapParser::parse($attributes));
    }
}
