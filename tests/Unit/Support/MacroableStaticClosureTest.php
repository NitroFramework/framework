<?php

namespace Tests\Unit\Support;

use Nitro\Support\Macroable;
use PHPUnit\Framework\TestCase;

class MacroHost
{
    use Macroable;
}

/**
 * A macro registered as a static closure can't be bound to $this (bindTo
 * returns null). The dispatcher must invoke it unbound instead of fataling on
 * a null call.
 */
class MacroableStaticClosureTest extends TestCase
{
    public function test_static_closure_macro_is_callable(): void
    {
        MacroHost::macro('shout', static fn(string $s): string => strtoupper($s));

        $this->assertSame('HI', (new MacroHost())->shout('hi'));
    }

    public function test_instance_closure_macro_binds_this(): void
    {
        MacroHost::macro('who', function (): string {
            return static::class;
        });

        $this->assertSame(MacroHost::class, (new MacroHost())->who());
    }
}
