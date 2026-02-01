<?php

namespace Keepsuit\LaravelOpenTelemetry\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

/**
 * @property int $id
 * @property string $name
 */
class Product extends Model
{
    use Searchable;

    protected $guarded = [];
}
