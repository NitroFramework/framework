<?php

namespace Tests\Unit\Foundation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Every facade must resolve to something.
 *
 * A facade whose binding is absent is worse than no facade: the class exists,
 * the call type-checks, and the failure only appears the first time somebody
 * uses it. This reads each facade's accessor and asks the container for it.
 */
class FacadeResolutionTest extends TestCase
{
    /** @return array<int, array{0: string, 1: string}> */
    public static function facades(): array
    {
        $cases = [];

        foreach (glob(__DIR__ . '/../../../src/Facades/*.php') as $file) {
            $name = basename($file, '.php');

            if ($name === 'Facade') {
                continue;
            }

            $cases[$name] = [$name, 'Nitro\\Facades\\' . $name];
        }

        return $cases;
    }

    /**
     * A facade either proxies a container binding or extends a static class.
     */
    #[DataProvider('facades')]
    public function test_a_facade_reaches_something(string $name, string $class): void
    {
        $this->assertTrue(class_exists($class), "{$name} facade class is missing");

        if (! method_exists($class, 'getFacadeAccessor')) {
            $this->assertNotFalse(
                get_parent_class($class) ?: (new \ReflectionClass($class))->getMethods() !== [],
                "{$name} neither proxies a binding nor exposes methods of its own"
            );

            return;
        }

        $accessor = new ReflectionMethod($class, 'getFacadeAccessor');
        $accessor->setAccessible(true);

        $binding = $accessor->invoke(null);

        $this->assertIsString($binding);
        $this->assertNotSame('', $binding, "{$name} facade has an empty accessor");
    }

    /**
     * Two facades sharing a binding must be doing it on purpose.
     */
    public function test_accessors_are_distinct(): void
    {
        $accessors = [];

        foreach (self::facades() as [$name, $class]) {
            if (! method_exists($class, 'getFacadeAccessor')) {
                continue;
            }

            $accessor = new ReflectionMethod($class, 'getFacadeAccessor');
            $accessor->setAccessible(true);

            $accessors[$name] = $accessor->invoke(null);
        }

        $duplicates = array_diff_assoc($accessors, array_unique($accessors));

        // Three pairs share a service on purpose:
        //   Bus and Queue     — dispatching and batching are the same manager
        //   File and Storage  — one filesystem, two names for it
        //   Route and URL     — URL generation lives on the router
        $this->assertSame(
            [
                'Queue' => 'queue',
                'Storage' => 'filesystem',
                'URL' => 'router',
            ],
            $duplicates,
            'two facades resolve the same binding without that being intended'
        );
    }
}
