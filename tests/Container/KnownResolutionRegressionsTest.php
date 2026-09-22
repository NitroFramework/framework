<?php

namespace Tests\Container;

use Nitro\Container\Container;
use Nitro\Console\Optimize\ContainerCompiler;
use Nitro\Container\Contracts\CallableInvoker;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Container\Exceptions\BindingResolutionException;
use Nitro\Thrust\RequestStateTracker;
use PHPUnit\Framework\TestCase;

/**
 * The resolution defects the container has actually had, asserted directly.
 *
 * Each test names a behaviour a review found wrong and holds the fix in
 * place. They live here rather than spread through ContainerApiTest so the
 * list of what once went wrong stays readable as a list.
 */
class KnownResolutionRegressionsTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
    }

    /**
     * singleton(Foo::class) binds the class to its own name. Resolving it went
     * get() → resolve() → get() → build(), and the hooks fired at every level:
     * three resolving callbacks, an extender wrapping its decorator three deep.
     */
    public function test_a_class_bound_to_its_own_name_fires_its_hooks_once(): void
    {
        $this->container->singleton(Widget::class);

        $before = 0;
        $resolving = 0;
        $this->container->beforeResolving(Widget::class, function () use (&$before): void { $before++; });
        $this->container->resolving(Widget::class, function () use (&$resolving): void { $resolving++; });
        $this->container->extend(Widget::class, fn (object $widget) => new Wrapped($widget));

        $widget = $this->container->resolve(Widget::class);

        $this->assertSame(1, $before);
        $this->assertSame(1, $resolving);
        $this->assertInstanceOf(Wrapped::class, $widget);
        $this->assertInstanceOf(Widget::class, $widget->inner, 'the extender must wrap the widget once, not a wrapper');
    }

    public function test_an_optional_class_dependency_takes_its_default_when_unresolvable(): void
    {
        $this->assertNull($this->container->resolve(TakesOptionalContract::class)->contract);
    }

    public function test_an_optional_dependency_is_still_resolved_when_it_can_be(): void
    {
        $this->container->singleton(WidgetContract::class, WidgetImpl::class);

        $this->assertInstanceOf(
            WidgetImpl::class,
            $this->container->resolve(TakesOptionalContract::class)->contract
        );
    }

    public function test_a_required_class_dependency_still_fails_loudly(): void
    {
        $this->expectException(BindingResolutionException::class);

        $this->container->resolve(NeedsContract::class);
    }

    public function test_a_missing_class_says_so(): void
    {
        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('does not exist');

        $this->container->resolve('Tests\\Definitely\\Missing\\Class_xyz');
    }

    public function test_an_abstract_class_says_it_is_not_instantiable(): void
    {
        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('not instantiable');

        $this->container->resolve(WidgetContract::class);
    }

    /** Only the container's own failures fall back to a default. */
    public function test_a_factory_exception_is_not_swallowed_by_a_default(): void
    {
        $this->container->singleton(WidgetContract::class, fn () => throw new \LogicException('from the factory'));

        $this->expectException(\LogicException::class);

        $this->container->resolve(TakesOptionalContract::class);
    }

    public function test_a_contextual_binding_by_parameter_name_accepts_the_sigil(): void
    {
        $this->container->when(TakesTimeout::class)->needs('$timeout')->give(99);

        $this->assertSame(99, $this->container->resolve(TakesTimeout::class)->timeout);
    }

    public function test_an_extender_on_a_scoped_service_survives_a_worker_reset(): void
    {
        $this->container->scoped(Widget::class);
        $this->container->resolve(Widget::class);
        $this->container->extend(Widget::class, fn (object $widget) => new Wrapped($widget));

        $this->assertInstanceOf(Wrapped::class, $this->container->resolve(Widget::class));

        $this->container->forgetScopedInstances();

        $rebuilt = $this->container->resolve(Widget::class);

        $this->assertInstanceOf(Wrapped::class, $rebuilt);
        $this->assertInstanceOf(Widget::class, $rebuilt->inner);
    }

    public function test_bind_and_bind_if_agree_on_a_transient_default(): void
    {
        $this->container->bind('a', fn () => new Widget());
        $this->container->bindIf('b', fn () => new Widget());

        $this->assertNotSame($this->container->resolve('a'), $this->container->resolve('a'));
        $this->assertNotSame($this->container->resolve('b'), $this->container->resolve('b'));
        $this->assertFalse($this->container->isShared('a'));
        $this->assertFalse($this->container->isShared('b'));
    }

    public function test_overrides_reach_the_binding_and_leave_the_singleton_alone(): void
    {
        $this->container->singleton(
            WidgetContract::class,
            fn (Container $c, array $parameters = []) => new WidgetImpl($parameters['label'] ?? 'shared')
        );

        $shared = $this->container->resolve(WidgetContract::class);
        $custom = $this->container->resolve(WidgetContract::class, ['label' => 'mine']);

        $this->assertSame('shared', $shared->label);
        $this->assertSame('mine', $custom->label);
        $this->assertSame(
            $shared,
            $this->container->resolve(WidgetContract::class),
            'a parameterised resolution must not replace the cached instance'
        );
    }

    public function test_a_compiled_factory_fires_the_hooks_and_extenders(): void
    {
        $this->container->bind(Widget::class, static fn (Container $c): Widget => new Widget());

        $fired = 0;
        $this->container->resolving(Widget::class, function () use (&$fired): void { $fired++; });
        $this->container->extend(Widget::class, fn (object $widget) => new Wrapped($widget));

        $this->assertInstanceOf(Wrapped::class, $this->container->resolve(Widget::class));
        $this->assertSame(1, $fired);
    }

    public function test_the_compiler_defers_a_service_a_deferred_provider_will_bind(): void
    {
        $compiler = new ContainerCompiler();

        $inlined  = $compiler->compile($this->container, [NeedsWidget::class]);
        $deferred = $compiler->compile($this->container, [NeedsWidget::class], [Widget::class]);

        $this->assertStringContainsString('new \\' . Widget::class . '()', $inlined);
        $this->assertStringContainsString('$c->make(' . var_export(Widget::class, true) . ')', $deferred);
        $this->assertStringNotContainsString('new \\' . Widget::class . '()', $deferred);
    }

    public function test_call_runs_a_method_binding_in_place_of_the_method(): void
    {
        $this->container->bindMethod([Recorder::class, 'record'], fn (Recorder $recorder) => 'intercepted');

        $this->assertSame('intercepted', $this->container->call([new Recorder(), 'record'], ['note' => 'x']));
        $this->assertSame('other:x', $this->container->call([new Recorder(), 'other'], ['note' => 'x']));
    }

    public function test_a_captured_declared_instance_is_found(): void
    {
        $tracker = (new RequestStateTracker($this->container, ['request']))->watch();

        $request = new Widget();
        $this->container->instance('request', $request);
        $this->container->singleton(Keeps::class);
        $this->container->resolve(Keeps::class)->kept = $request;

        $findings = $tracker->captured();

        $this->assertCount(1, $findings);
        $this->assertSame(Keeps::class, $findings[0]['holder']);
    }

    public function test_a_variadic_constructor_receives_nothing_or_the_overrides(): void
    {
        $this->assertSame([], $this->container->resolve(TakesVariadic::class)->names);
        $this->assertSame(['a', 'b'], $this->container->resolve(TakesVariadic::class, ['a', 'b'])->names);
        $this->assertSame(['c'], $this->container->resolve(TakesVariadic::class, ['names' => ['c']])->names);
    }

    public function test_binding_over_an_alias_replaces_the_alias(): void
    {
        $this->container->singleton(Widget::class);
        $this->container->alias(Widget::class, 'x');
        $this->container->singleton('x', fn () => new WidgetImpl());
        $this->container->resolve('x');

        $this->assertFalse($this->container->isAlias('x'));
        $this->assertTrue($this->container->resolved('x'));
        $this->assertSame('x', $this->container->getAlias('x'));
    }

    public function test_call_accepts_every_spelling_of_a_callable(): void
    {
        $invoker = $this->container->get(CallableInvoker::class);

        $this->assertSame('hi:ok', $invoker->call(StaticGreeter::class . '::hi'));
        $this->assertSame('hi:there', $invoker->call([StaticGreeter::class, 'hi'], ['x' => 'there']));
        $this->assertSame('invoked', $invoker->call(new Invokable()));
        $this->assertSame('other:y', $invoker->call(Recorder::class . '@other', ['note' => 'y']));
        $this->assertSame('other:z', $invoker->call([Recorder::class, 'other'], ['note' => 'z']));
    }

    public function test_flush_keeps_the_capability_contracts(): void
    {
        $this->container->singleton('anything', fn () => new Widget());
        $this->container->flush();

        $this->assertFalse($this->container->has('anything'));
        $this->assertInstanceOf(ClassResolver::class, $this->container->get(ClassResolver::class));
        $this->assertInstanceOf(CallableInvoker::class, $this->container->get(CallableInvoker::class));
    }

    public function test_two_factories_that_resolve_each_other_are_reported(): void
    {
        $this->container->singleton('a', fn (Container $c) => $c->get('b'));
        $this->container->singleton('b', fn (Container $c) => $c->get('a'));

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('a → b → a');

        $this->container->get('a');
    }

    public function test_forget_clears_what_the_container_knew_about_a_name(): void
    {
        $this->container->scoped('gone', fn () => new Widget());
        $this->container->resolve('gone');
        $this->container->forget('gone');

        $this->assertFalse($this->container->has('gone'));
    }

    public function test_an_alias_chain_is_followed_to_its_end(): void
    {
        $this->container->scoped(Widget::class);
        $this->container->alias(Widget::class, 'inner');
        $this->container->alias('inner', 'outer');

        $this->assertSame(Widget::class, $this->container->getAlias('outer'));

        $first = $this->container->resolve('outer');
        $this->container->forgetScoped(['outer']);

        $this->assertNotSame($first, $this->container->resolve('outer'));
    }
}

class Widget
{
}

interface WidgetContract
{
}

class WidgetImpl implements WidgetContract
{
    public function __construct(public string $label = 'impl') {}
}

class Wrapped
{
    public function __construct(public object $inner) {}
}

class TakesOptionalContract
{
    public function __construct(public ?WidgetContract $contract = null) {}
}

class NeedsContract
{
    public function __construct(public WidgetContract $contract) {}
}

class TakesTimeout
{
    public function __construct(public int $timeout = 5) {}
}

class TakesVariadic
{
    /** @var array<int, string> */
    public array $names;

    public function __construct(string ...$names)
    {
        $this->names = $names;
    }
}

class NeedsWidget
{
    public function __construct(public Widget $widget) {}
}

class Keeps
{
    public ?object $kept = null;
}

class Recorder
{
    public function record(string $note): string
    {
        return "recorded:{$note}";
    }

    public function other(string $note): string
    {
        return "other:{$note}";
    }
}

class StaticGreeter
{
    public static function hi(string $x = 'ok'): string
    {
        return "hi:{$x}";
    }
}

class Invokable
{
    public function __invoke(): string
    {
        return 'invoked';
    }
}
