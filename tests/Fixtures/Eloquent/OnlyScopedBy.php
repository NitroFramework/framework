<?php

namespace Nitro\Tests\Fixtures\Eloquent;

use Attribute;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;

/**
 * A subclass of #[ScopedBy]: Laravel matches scope attributes by instanceof.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class OnlyScopedBy extends ScopedBy
{
}
