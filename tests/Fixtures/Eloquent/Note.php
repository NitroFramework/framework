<?php

namespace Nitro\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Refreshes;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Visible;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Every #[Table] option, the other class attributes Eloquent reads, a scope named by a subclass
 * of #[ScopedBy], and a private boot method.
 */
#[OnlyScopedBy(ActiveScope::class)]
#[Table(name: 'notes_table', key: 'uuid', keyType: 'string', incrementing: false)]
#[Connection('secondary')]
#[WithoutTimestamps]
#[RouteKey('uuid')]
#[Refreshes(['uuid', 'body'])]
#[Visible(['uuid', 'body'])]
class Note extends Model
{
    use Secret;
}
