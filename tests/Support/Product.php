<?php

namespace Keepsuit\LaravelOpenTelemetry\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 */
class Product extends Model
{
    protected $table = 'products';

    protected $guarded = [];
}
