<?php

namespace Tests\Unit\Container;

use Nitro\Container\Container;
use Nitro\Container\Exceptions\NotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * createOrResolve() is the framework's standard way to ask the container for an
 * instance — the name states what it does: resolve the registered binding, or
 * create it by auto-wiring when nothing is bound.
 *
 * make() is the same method under its conventional name and stays supported.
 * get() is deliberately different: a strict registry lookup that throws.
 */
class CreateOrResolveTest extends TestCase
{
    public function test_it_resolves_a_registered_binding(): void
    {
        $c = new Container();
        $c->singleton('thing', fn() => new CorService('bound'));

        $this->assertSame('bound', $c->createOrResolve('thing')->tag);
    }

    public function test_it_creates_an_unbound_class_by_auto_wiring(): void
    {
        $c = new Container();

        $needs = $c->createOrResolve(CorNeedsService::class);

        $this->assertInstanceOf(CorNeedsService::class, $needs);
        $this->assertInstanceOf(CorService::class, $needs->service);
    }

    public function test_make_is_the_same_method_under_its_conventional_name(): void
    {
        $c = new Container();
        $c->singleton('thing', fn() => new CorService('bound'));

        $this->assertSame($c->createOrResolve('thing'), $c->make('thing'));
        $this->assertInstanceOf(CorNeedsService::class, $c->make(CorNeedsService::class));
    }

    public function test_a_singleton_resolves_to_the_same_instance_either_way(): void
    {
        $c = new Container();
        $c->singleton(CorService::class);

        $this->assertSame($c->createOrResolve(CorService::class), $c->make(CorService::class));
    }

    public function test_constructor_overrides_are_honoured(): void
    {
        $c = new Container();

        $this->assertSame('explicit', $c->createOrResolve(CorService::class, ['tag' => 'explicit'])->tag);
    }

    // ─── the deliberate difference from get() ───────────────────────────────

    public function test_get_throws_for_an_unregistered_name(): void
    {
        $this->expectException(NotFoundException::class);

        (new Container())->get(CorService::class);
    }

    public function test_create_or_resolve_auto_wires_where_get_would_throw(): void
    {
        // The whole distinction in one pair of lines: same argument, same
        // container — get() refuses, createOrResolve() builds it.
        $c = new Container();

        $this->assertInstanceOf(CorService::class, $c->createOrResolve(CorService::class));
    }

    public function test_both_agree_once_the_binding_exists(): void
    {
        $c = new Container();
        $c->singleton(CorService::class);

        $this->assertSame($c->get(CorService::class), $c->createOrResolve(CorService::class));
    }
}

class CorService
{
    public function __construct(public string $tag = 'auto') {}
}

class CorNeedsService
{
    public function __construct(public CorService $service) {}
}
