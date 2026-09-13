<?php

namespace Tests\Unit\Http;

use Nitro\Http\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * 'platform:admin' is an alias plus an argument.
 *
 * Read whole, it resolves to nothing and the middleware is skipped — which on a
 * guard leaves the route wide open while the route list still shows it as
 * protected. That is the worst shape an authorisation bug can take: it looks
 * right everywhere you would think to check.
 */
class MiddlewareParametersTest extends TestCase
{
    /** @return array{0: string, 1: array<int, string>} */
    private function parse(string $name): array
    {
        $method = new \ReflectionMethod(Kernel::class, 'parseMiddlewareName');
        $method->setAccessible(true);

        return $method->invoke($method->getDeclaringClass()->newInstanceWithoutConstructor(), $name);
    }

    public function test_a_plain_alias_takes_no_arguments(): void
    {
        $this->assertSame(['auth', []], $this->parse('auth'));
    }

    public function test_one_argument_is_split_off(): void
    {
        $this->assertSame(['platform', ['admin']], $this->parse('platform:admin'));
    }

    public function test_several_arguments_are_split_on_commas(): void
    {
        $this->assertSame(['role', ['owner', 'admin']], $this->parse('role:owner,admin'));
    }

    public function test_a_class_name_is_left_alone(): void
    {
        // A fully-qualified class cannot contain a colon, but the check comes
        // first so nothing unusual is ever cut in half.
        $this->assertSame([Kernel::class, []], $this->parse(Kernel::class));
    }

    public function test_a_trailing_colon_is_not_an_empty_argument(): void
    {
        $this->assertSame(['platform', []], $this->parse('platform:'));
    }
}
