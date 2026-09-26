<?php

namespace Nitro\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;

/**
 * Class attributes declared on a trait, including a global scope.
 */
#[Fillable(['title', 'body'])]
#[Hidden('secret')]
#[Tag('from trait')]
#[ScopedBy(PublishedScope::class)]
trait Guarding
{
}
