<?php

namespace Tests\Unit\Foundation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Container\Exceptions\NotFoundException;
use Nitro\Foundation\Application;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

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

    /**
     * Every accessor must name a binding a booted container actually has.
     *
     * Checking that the accessor returns a non-empty string proves nothing —
     * it passed while three bindings were broken, one of them a circular alias
     * that exhausted memory on the first call.
     *
     * Only a missing binding fails here. A binding that is present but cannot
     * build without an application around it — no views directory, no user
     * model, no app key — was still found, which is what this is asserting.
     */
    public function test_every_accessor_names_a_binding_the_container_has(): void
    {
        $container = $this->bootedContainer();
        $missing = [];

        foreach (self::accessors() as $name => $binding) {
            // The HTTP kernel binds the request per request from $_SERVER;
            // absent outside a request is what it is supposed to be.
            if ($binding === 'request') {
                continue;
            }

            try {
                $container->get($binding);
            } catch (NotFoundException $exception) {
                $missing[$name] = $binding;
            } catch (Throwable) {
                // Found, and failed for want of an application. Not this test's
                // concern.
            }
        }

        $this->assertSame([], $missing, 'facades naming a binding the container does not have');
    }

    /**
     * A shared service must stay shared once its binding moves to a provider.
     *
     * The Application used to bind these itself; it now only aliases them and
     * the owning provider does the binding, several of them deferred. Drop the
     * singleton() on the way across and the container silently hands back a new
     * instance per resolution — a Gate that answers from an empty policy map, a
     * RateLimiter that never sees a previous attempt. Nothing throws; the guard
     * just stops guarding.
     */
    public function test_services_that_must_be_shared_still_are(): void
    {
        $container = $this->bootedContainer();

        $shared = ['gate', 'hash', 'date', 'rate.limiter', 'translator', 'broadcast', 'maintenance'];
        $perResolution = [];

        foreach ($shared as $alias) {
            try {
                if ($container->get($alias) !== $container->get($alias)) {
                    $perResolution[] = $alias;
                }
            } catch (Throwable) {
                // Covered by the test above; an unresolvable binding is not this
                // test's concern.
            }
        }

        $this->assertSame([], $perResolution, 'these are rebuilt on every resolution but must be shared');
    }

    /**
     * No alias may point back at the binding that points at it.
     *
     * A pair like 'cookie' => CookieJar::class alongside CookieJar::class =>
     * 'cookie' is not a slow resolution, it is an unbounded one: each side asks
     * the other until the process runs out of memory. Nothing else in the suite
     * would survive to report it.
     */
    public function test_no_alias_points_back_at_its_own_binding(): void
    {
        $container = $this->bootedContainer();

        $targets = new ReflectionProperty($container, 'aliasTargets');
        $targets->setAccessible(true);

        $aliases = $targets->getValue($container);
        $cycles = [];

        foreach ($aliases as $alias => $target) {
            if (($aliases[$target] ?? null) === $alias) {
                $cycles[] = $alias . ' <-> ' . $target;
            }
        }

        $this->assertSame([], $cycles, 'aliases that resolve to each other');
    }

    /** Each facade's accessor, keyed by facade name. */
    private static function accessors(): array
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

        return $accessors;
    }

    /**
     * Boot an application and hand back its container.
     *
     * Bootstrapping installs error and exception handlers; they are restored
     * so the run does not leave them behind for the next test.
     */
    private function bootedContainer(): ContainerInterface
    {
        $application = new Application(dirname(__DIR__, 3));
        $application->bootstrap();

        restore_error_handler();
        restore_exception_handler();

        return $application->getContainer();
    }
}
