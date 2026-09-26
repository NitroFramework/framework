<?php

namespace Nitro\Tests\Fixtures\Eloquent;

use Attribute;

/**
 * An application's own class attribute, holding any value (a string, an enum, an object).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Tag
{
    public function __construct(public mixed $value)
    {
    }
}
