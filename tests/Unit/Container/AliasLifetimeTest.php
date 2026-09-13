<?php

namespace Tests\Unit\Container;

use Nitro\Container\Container;
use PHPUnit\Framework\TestCase;

/** Stand-in for a per-request service: a guard, a session, a basket. */
class PerRequestThing
{
    public function __construct(public string $id)
    {
        $this->id = bin2hex(random_bytes(4));
    }
}

interface ThingContract {}

class ConcreteThing implements ThingContract {}

/**
 * An alias must not decide the lifetime of what it points at.
 *
 * alias() used to register itself as a singleton, which silently promoted
 * whatever it aliased. Over a scoped binding that is a worker-mode data leak:
 * alias(Guard::class, 'auth') handed out the first request's guard for the life
 * of the process, still holding that request's session.
 *
 * The way it actually surfaced was stranger and worse than a plain leak — the
 * application disagreed with itself. auth() read the scoped target and said
 * signed in; the auth middleware type-hinted the aliased contract and said
 * signed out. Login "worked" and every guarded page bounced to the login form.
 */
class AliasLifetimeTest extends TestCase
{
    private function container(): Container
    {
        Container::reset();

        return new Container();
    }

    public function test_an_alias_over_a_scoped_binding_stays_scoped(): void
    {
        $container = $this->container();

        $container->scoped('thing', fn () => new PerRequestThing(''));
        $container->alias(ThingContract::class, 'thing');

        $first = $container->createOrResolve(ThingContract::class);

        // What ends a worker request.
        $container->forgetScopedInstances();

        $second = $container->createOrResolve(ThingContract::class);

        $this->assertNotSame($first, $second, 'the alias cached across requests');
    }

    public function test_the_alias_and_its_target_agree_within_one_request(): void
    {
        $container = $this->container();

        $container->scoped('thing', fn () => new PerRequestThing(''));
        $container->alias(ThingContract::class, 'thing');

        // The specific failure: one half of the application read the target and
        // the other read the alias, and they saw different objects.
        $this->assertSame(
            $container->createOrResolve('thing'),
            $container->createOrResolve(ThingContract::class),
        );
    }

    public function test_an_alias_over_a_singleton_is_still_one_instance(): void
    {
        $container = $this->container();

        $container->singleton('thing', fn () => new ConcreteThing());
        $container->alias(ThingContract::class, 'thing');

        $first = $container->createOrResolve(ThingContract::class);
        $container->forgetScopedInstances();

        // The target's binding decides, and here it says singleton.
        $this->assertSame($first, $container->createOrResolve(ThingContract::class));
        $this->assertSame($first, $container->createOrResolve('thing'));
    }

    public function test_a_chain_of_aliases_still_reaches_the_target(): void
    {
        $container = $this->container();

        $container->singleton('thing', fn () => new ConcreteThing());
        $container->alias(ThingContract::class, 'thing');
        $container->alias(ConcreteThing::class, ThingContract::class);

        $this->assertSame(
            $container->createOrResolve('thing'),
            $container->createOrResolve(ConcreteThing::class),
        );
    }
}
