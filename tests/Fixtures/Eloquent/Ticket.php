<?php

namespace Nitro\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Overridden boot(), booted() and resolveObserveAttributes(), a static boot-like method no trait
 * asks for, #[Unguarded] beside #[Guarded], a custom builder, and an attribute named in another
 * letter case (class names are case-insensitive, and so is Laravel's lookup).
 */
#[Unguarded]
#[Guarded(['id'])]
#[UseEloquentBuilder(Builder::class)]
#[\Illuminate\Database\Eloquent\Attributes\TOUCHES(['owner'])]
class Ticket extends Model
{
    use Tagged;

    protected $table = 'tickets';

    /**
     * A model resolving its own observers on top of Laravel's: Nitro leaves this to the model,
     * and to its children (Task), which inherit it.
     */
    public static function resolveObserveAttributes()
    {
        BootLog::record('own resolveObserveAttributes', static::class);

        return array_merge(parent::resolveObserveAttributes(), [ArticleObserver::class]);
    }

    public static function bootUnrelated(): void
    {
        BootLog::record('bootUnrelated', static::class);
    }

    protected static function boot(): void
    {
        BootLog::record('boot before', static::class);

        parent::boot();

        BootLog::record('boot after', static::class);
    }

    protected static function booted(): void
    {
        BootLog::record('booted', static::class);

        static::addGlobalScope('open', static fn ($query) => $query);
    }
}
