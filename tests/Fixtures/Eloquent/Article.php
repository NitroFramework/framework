<?php

namespace Nitro\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\Touches;
use Illuminate\Database\Eloquent\SoftDeletes;
use Nitro\Tests\Fixtures\Classes\Status;

/**
 * Inherited and own traits, a trait used twice (directly by the parent, through Publishable
 * here), attribute-marked methods, a framework trait with a global scope, class attributes of
 * its own and from a trait (Guarding), a repeated attribute and an enum argument, an observer
 * and global scopes of its own and from a trait.
 */
#[ObservedBy(ArticleObserver::class)]
#[ScopedBy([ActiveScope::class])]
#[Appends(['summary'])]
#[Touches(['base'])]
#[DateFormat('Y-m-d')]
#[Tag(Status::Published)]
#[Tag('second')]
class Article extends Base
{
    use Audited, Guarding, Publishable, SoftDeletes;
}
