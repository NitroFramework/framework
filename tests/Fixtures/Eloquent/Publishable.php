<?php

namespace Nitro\Tests\Fixtures\Eloquent;

/**
 * A trait using another trait, which the model may also use directly.
 */
trait Publishable
{
    use Tagged;

    public static function bootPublishable(): void
    {
        BootLog::record('bootPublishable', static::class);

        static::addGlobalScope('published', static fn ($query) => $query);
    }
}
