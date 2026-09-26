<?php

namespace Nitro\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;

/**
 * A model extending a concrete model: its own #[Hidden] wins over the one Article's trait
 * declares, its observer is added to Article's, the rest is inherited.
 */
#[WithoutIncrementing]
#[ObservedBy([CommentObserver::class])]
#[Hidden(['internal', 'notes'])]
class Comment extends Article
{
    use Secret;
}
