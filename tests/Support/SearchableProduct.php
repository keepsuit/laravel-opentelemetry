<?php

namespace Keepsuit\LaravelOpenTelemetry\Tests\Support;

use Laravel\Scout\Searchable;

class SearchableProduct extends Product
{
    use Searchable;
}
