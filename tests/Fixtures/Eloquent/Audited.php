<?php

namespace Nitro\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Initialize;

/**
 * Boot and initialize methods marked by attribute rather than by name.
 */
trait Audited
{
    #[Boot]
    public static function registerAudit(): void
    {
        BootLog::record('registerAudit', static::class);
    }

    #[Initialize]
    public function prepareAudit(): void
    {
        BootLog::record('prepareAudit', static::class);
    }
}
