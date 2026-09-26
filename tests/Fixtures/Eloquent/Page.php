<?php

namespace Nitro\Tests\Fixtures\Eloquent;

use ArrayObject;
use Illuminate\Database\Eloquent\Attributes\Table;

/**
 * #[Table] on a model whose parent declares $table: the attribute's name wins. Its #[Tag] holds
 * an object made with `new`, so its class attributes resolve through Laravel's code.
 */
#[Table('pages')]
#[Tag(new ArrayObject(['made' => 'with new']))]
class Page extends Base
{
}
