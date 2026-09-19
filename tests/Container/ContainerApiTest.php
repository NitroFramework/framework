<?php

namespace Tests\Container;

use Nitro\Container\Container;
use Nitro\Container\ContextualBindingBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Resolution hooks, decoration, contextual binding and the binding-management
 * surface.
 */
class ContainerApiTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    // ─── Introspection ────────────────────────────────────

    public function test_bound_and_resolved(): void
    {
        $this->assertFalse($this->container->bound('thing'));

        $this->container->singleton('thing', fn () => new \stdClass());

        $this->assertTrue($this->container->bound('thing'));
        $this->assertFalse($this->container->resolved('thing'));

        $this->container->get('thing');

        $this->assertTrue($this->container->resolved('thing'));
    }

    public function test_is_shared(): void
    {
        $this->container->singleton('shared', fn () => new \stdClass());
        $this->container->bind('transient', fn () => new \stdClass(), false);

        $this->assertTrue($this->container->isShared('shared'));
        $this->assertFalse($this->container->isShared('transient'));
    }

    public function test_alias_accessors(): void
    {
        $this->container->singleton('real', fn () => new \stdClass());
        $this->container->alias('nickname', 'real');

        $this->assertTrue($this->container->isAlias('nickname'));
        $this->assertFalse($this->container->isAlias('real'));
        $this->assertSame('real', $this->container->getAlias('nickname'));
        $this->assertSame('real', $this->container->getAlias('real'));
    }

    public function test_get_bindings(): void
    {
        $this->container->singleton('a', fn () => 1);

        $this->assertArrayHasKey('a', $this->container->getBindings());
    }

    // ─── Conditional binding ──────────────────────────────

    public function test_bind_if_does_not_override(): void
    {
        $this->container->singleton('svc', fn () => 'first');
        $this->container->bindIf('svc', fn () => 'second');

        $this->assertSame('first', $this->container->get('svc'));
    }

    public function test_bind_if_registers_when_absent(): void
    {
        $this->container->bindIf('svc', fn () => 'only', true);

        $this->assertSame('only', $this->container->get('svc'));
    }

    public function test_singleton_if(): void
    {
        $this->container->singletonIf('svc', fn () => new \stdClass());
        $first = $this->container->get('svc');

        $this->container->singletonIf('svc', fn () => new \stdClass());

        $this->assertSame($first, $this->container->get('svc'));
    }

    // ─── Resolution hooks ─────────────────────────────────

    public function test_resolving_callback_for_one_abstract(): void
    {
        $seen = [];

        $this->container->singleton('svc', fn () => new \stdClass());
        $this->container->resolving('svc', function ($instance) use (&$seen) {
            $seen[] = $instance;
        });

        $resolved = $this->container->get('svc');

        $this->assertCount(1, $seen);
        $this->assertSame($resolved, $seen[0]);
    }

    public function test_global_resolving_callback_sees_everything(): void
    {
        $count = 0;

        $this->container->resolving(function () use (&$count) {
            $count++;
        });

        $this->container->singleton('a', fn () => new \stdClass());
        $this->container->singleton('b', fn () => new \stdClass());

        $this->container->get('a');
        $this->container->get('b');

        $this->assertSame(2, $count);
    }

    public function test_before_resolving_runs_first(): void
    {
        $order = [];

        $this->container->singleton('svc', fn () => new \stdClass());
        $this->container->beforeResolving('svc', function () use (&$order) {
            $order[] = 'before';
        });
        $this->container->resolving('svc', function () use (&$order) {
            $order[] = 'resolving';
        });
        $this->container->afterResolving('svc', function () use (&$order) {
            $order[] = 'after';
        });

        $this->container->get('svc');

        $this->assertSame(['before', 'resolving', 'after'], $order);
    }

    public function test_hooks_fire_for_autowired_classes(): void
    {
        $seen = null;

        $this->container->resolving(PlainService::class, function ($instance) use (&$seen) {
            $seen = $instance;
        });

        $resolved = $this->container->resolve(PlainService::class);

        $this->assertSame($resolved, $seen);
    }

    /** A container with no hooks must not run the callback machinery. */
    public function test_no_hooks_means_no_callback_work(): void
    {
        $this->container->singleton('svc', fn () => new \stdClass());

        $this->assertInstanceOf(\stdClass::class, $this->container->get('svc'));
    }

    // ─── Extending ────────────────────────────────────────

    public function test_extend_decorates_a_resolution(): void
    {
        $this->container->singleton('svc', fn () => 'base');
        $this->container->extend('svc', fn ($value) => $value . '+extended');

        $this->assertSame('base+extended', $this->container->get('svc'));
    }

    public function test_extenders_apply_in_order(): void
    {
        $this->container->singleton('svc', fn () => 'a');
        $this->container->extend('svc', fn ($v) => $v . 'b');
        $this->container->extend('svc', fn ($v) => $v . 'c');

        $this->assertSame('abc', $this->container->get('svc'));
    }

    /** Extending after resolution must still take effect. */
    public function test_extend_applies_to_an_already_resolved_singleton(): void
    {
        $this->container->singleton('svc', fn () => 'base');
        $this->container->get('svc');

        $this->container->extend('svc', fn ($value) => $value . '+late');

        $this->assertSame('base+late', $this->container->get('svc'));
    }

    public function test_forget_extenders(): void
    {
        $this->container->singleton('svc', fn () => 'base');
        $this->container->extend('svc', fn ($v) => $v . '+x');
        $this->container->forgetExtenders('svc');

        $this->assertSame('base', $this->container->get('svc'));
    }

    // ─── Contextual binding ───────────────────────────────

    public function test_when_needs_give(): void
    {
        $this->container->bind(Greeter::class, fn () => new Greeter('default'), false);

        $this->container->when(EnglishConsumer::class)
            ->needs(Greeter::class)
            ->give(fn () => new Greeter('hello'));

        $consumer = $this->container->resolve(EnglishConsumer::class);

        $this->assertSame('hello', $consumer->greeter->word);
    }

    public function test_contextual_binding_only_affects_the_named_consumer(): void
    {
        $this->container->bind(Greeter::class, fn () => new Greeter('default'), false);

        $this->container->when(EnglishConsumer::class)
            ->needs(Greeter::class)
            ->give(fn () => new Greeter('hello'));

        $other = $this->container->resolve(OtherConsumer::class);

        $this->assertSame('default', $other->greeter->word);
    }

    public function test_when_accepts_several_consumers(): void
    {
        $this->container->bind(Greeter::class, fn () => new Greeter('default'), false);

        $this->container->when([EnglishConsumer::class, OtherConsumer::class])
            ->needs(Greeter::class)
            ->give(fn () => new Greeter('shared'));

        $this->assertSame('shared', $this->container->resolve(EnglishConsumer::class)->greeter->word);
        $this->assertSame('shared', $this->container->resolve(OtherConsumer::class)->greeter->word);
    }

    public function test_when_returns_a_builder(): void
    {
        $this->assertInstanceOf(
            ContextualBindingBuilder::class,
            $this->container->when(EnglishConsumer::class)
        );
    }

    /** An unfinished chain records nothing. */
    public function test_give_without_needs_binds_nothing(): void
    {
        $this->container->bind(Greeter::class, fn () => new Greeter('default'), false);
        $this->container->when(EnglishConsumer::class)->give(fn () => new Greeter('ignored'));

        $this->assertSame('default', $this->container->resolve(EnglishConsumer::class)->greeter->word);
    }

    public function test_give_tagged(): void
    {
        $this->container->bind('one', fn () => 'first', false);
        $this->container->bind('two', fn () => 'second', false);
        $this->container->tag('things', 'one', 'two');

        $this->container->when(EnglishConsumer::class)->needs('$items')->giveTagged('things');

        $this->assertSame(
            ['first', 'second'],
            $this->container->tagged('things')
        );
    }

    // ─── Rebinding ────────────────────────────────────────

    public function test_rebinding_fires_when_a_binding_is_replaced(): void
    {
        $seen = [];

        $this->container->singleton('svc', fn () => 'first');
        $this->container->rebinding('svc', function ($instance) use (&$seen) {
            $seen[] = $instance;
        });

        $this->container->singleton('svc', fn () => 'second');

        $this->assertSame(['second'], $seen);
    }

    public function test_refresh_pushes_into_a_setter(): void
    {
        $target = new Holder();

        $this->container->singleton('svc', fn () => 'first');
        $this->container->refresh('svc', $target, 'setValue');

        $this->container->singleton('svc', fn () => 'second');

        $this->assertSame('second', $target->value);
    }

    // ─── Lifecycle ────────────────────────────────────────

    public function test_forget_instance_rebuilds_on_next_resolve(): void
    {
        $this->container->singleton('svc', fn () => new \stdClass());

        $first = $this->container->get('svc');
        $this->container->forgetInstance('svc');
        $second = $this->container->get('svc');

        $this->assertNotSame($first, $second);
    }

    public function test_forget_instances_keeps_bindings(): void
    {
        $this->container->singleton('svc', fn () => new \stdClass());
        $this->container->get('svc');

        $this->container->forgetInstances();

        $this->assertTrue($this->container->bound('svc'));
        $this->assertFalse($this->container->resolved('svc'));
    }

    public function test_flush_clears_everything(): void
    {
        $this->container->singleton('svc', fn () => new \stdClass());
        $this->container->alias('nick', 'svc');
        $this->container->get('svc');

        $this->container->flush();

        $this->assertFalse($this->container->bound('svc'));
        $this->assertFalse($this->container->isAlias('nick'));
    }

    // ─── make with parameters ─────────────────────────────

    public function test_make_with(): void
    {
        $instance = $this->container->makeWith(Greeter::class, ['word' => 'custom']);

        $this->assertSame('custom', $instance->word);
    }

    public function test_build_bypasses_bindings(): void
    {
        $this->container->singleton(Greeter::class, fn () => new Greeter('bound'));

        $built = $this->container->build(Greeter::class, ['word' => 'built']);

        $this->assertSame('built', $built->word);
        $this->assertSame('bound', $this->container->get(Greeter::class)->word);
    }

    // ─── ArrayAccess ──────────────────────────────────────

    public function test_array_access(): void
    {
        $this->container['svc'] = fn () => 'value';

        $this->assertTrue(isset($this->container['svc']));
        $this->assertSame('value', $this->container['svc']);

        unset($this->container['svc']);

        $this->assertFalse(isset($this->container['svc']));
    }

    public function test_array_access_wraps_a_plain_value(): void
    {
        $this->container['answer'] = 42;

        $this->assertSame(42, $this->container['answer']);
    }

    // ─── Method binding and wrapping ──────────────────────

    public function test_bind_method(): void
    {
        $this->container->bindMethod(
            Holder::class . '@setValue',
            fn ($instance) => 'intercepted'
        );

        $this->assertTrue($this->container->hasMethodBinding(Holder::class . '@setValue'));
        $this->assertSame(
            'intercepted',
            $this->container->callMethodBinding(Holder::class . '@setValue', new Holder())
        );
    }

    public function test_wrap_defers_the_call(): void
    {
        $wrapped = $this->container->wrap(fn () => 'called');

        $this->assertInstanceOf(\Closure::class, $wrapped);
        $this->assertSame('called', $wrapped());
    }
}

class PlainService
{
}

class Greeter
{
    public function __construct(public string $word = 'default') {}
}

class EnglishConsumer
{
    public function __construct(public Greeter $greeter) {}
}

class OtherConsumer
{
    public function __construct(public Greeter $greeter) {}
}

class Holder
{
    public mixed $value = null;

    public function setValue(mixed $value): void
    {
        $this->value = $value;
    }
}
