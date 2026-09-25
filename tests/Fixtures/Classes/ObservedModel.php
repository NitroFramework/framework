<?php

namespace Nitro\Tests\Fixtures\Classes;

use Illuminate\Database\Eloquent\Model;

/**
 * A model that registers an event listener when it boots, as observers and model events do.
 */
class ObservedModel extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(static fn () => null);
    }
}
