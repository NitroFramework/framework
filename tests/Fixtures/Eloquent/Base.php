<?php

namespace Nitro\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * An abstract parent model with a trait and its own $table.
 */
abstract class Base extends Model
{
    use Tagged;

    protected $table = 'bases';
}
