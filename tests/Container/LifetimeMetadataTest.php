<?php

namespace Tests\Container;

use Nitro\Container\Attributes\ProcessScoped;
use Nitro\Container\Attributes\RequestScoped;
use Nitro\Container\Container;
use Nitro\Container\Lifetime;
use PHPUnit\Framework\TestCase;

/**
 * How long the container believes a service lives.
 *
 * The container does not act on this while resolving — a dependency that
 * outlives its consumer resolves normally, as it does in any other container.
 * The metadata exists for `nitro lifetime:check`, which reports those pairings
 * so they can be looked at deliberately rather than discovered under load.
 *
 * Under FPM the process ends with the request, so a singleton holding request
 * state is indistinguishable from one that does not. In a worker it answers
 * every later request from the first one's data, and nothing says so.
 */
class LifetimeMetadataTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
    }

    // ─── What a lifetime is read from ─────────────────────

    public function test_a_binding_declares_its_lifetime(): void
    {
        $this->container->singleton('process', fn () => new Plain());
        $this->container->scoped('request', fn () => new Plain());
        $this->container->bind('transient', fn () => new Plain(), false);

        $this->assertSame(Lifetime::Process, $this->container->lifetimeOf('process'));
        $this->assertSame(Lifetime::Request, $this->container->lifetimeOf('request'));
        $this->assertSame(Lifetime::Transient, $this->container->lifetimeOf('transient'));
    }

    public function test_an_unbound_class_is_transient(): void
    {
        // Built fresh on every resolution, so it takes the lifetime of whatever
        // asked for it rather than having one of its own.
        $this->assertSame(Lifetime::Transient, $this->container->lifetimeOf(Plain::class));
    }

    public function test_the_class_decides_over_the_binding(): void
    {
        // Registered as a singleton by a provider that did not know better. The
        // attribute is on the class, where its author is, so it wins.
        $this->container->singleton(HoldsTheRequest::class);

        $this->assertSame(Lifetime::Request, $this->container->lifetimeOf(HoldsTheRequest::class));
    }

    public function test_an_alias_answers_for_its_target(): void
    {
        $this->container->scoped('session', fn () => new Plain());
        $this->container->alias(Plain::class, 'session');

        // An alias that decided its own lifetime is how a scoped service comes
        // to be resolved twice, as two different objects, in one request.
        $this->assertSame(Lifetime::Request, $this->container->lifetimeOf(Plain::class));
    }

    public function test_lifetimes_can_be_declared_without_binding(): void
    {
        $this->container->declareLifetime('request', Lifetime::Request);

        $this->assertSame(Lifetime::Request, $this->container->lifetimeOf('request'));
    }

    // ─── What it does not do ──────────────────────────────

    /**
     * The shape the checker reports on still resolves. Refusing it here would
     * reject bindings that every other PHP container accepts, and a great deal
     * of existing application and package code with them.
     */
    public function test_a_singleton_may_take_a_request_scoped_dependency(): void
    {
        $this->container->scoped(HoldsTheRequest::class, fn () => new HoldsTheRequest());
        $this->container->singleton(LivesForOneRequest::class, LivesForOneRequest::class);

        $this->assertInstanceOf(
            LivesForOneRequest::class,
            $this->container->get(LivesForOneRequest::class)
        );
    }

    public function test_a_transient_in_between_resolves_too(): void
    {
        $this->container->scoped(HoldsTheRequest::class, fn () => new HoldsTheRequest());
        $this->container->singleton(LivesForeverIndirectly::class, LivesForeverIndirectly::class);

        $this->assertInstanceOf(
            LivesForeverIndirectly::class,
            $this->container->get(LivesForeverIndirectly::class)
        );
    }
}

class Plain
{
}

#[RequestScoped]
class HoldsTheRequest
{
}

#[ProcessScoped]
class Permanent
{
}

class Passes
{
    public function __construct(public HoldsTheRequest $request)
    {
    }
}

class LivesForeverIndirectly
{
    public function __construct(public Passes $passes)
    {
    }
}

class LivesForOneRequest
{
    public function __construct(public HoldsTheRequest $request)
    {
    }
}

class NeedsSomethingPermanent
{
    public function __construct(public Permanent $permanent)
    {
    }
}
