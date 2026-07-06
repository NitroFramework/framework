<?php

namespace Tests\Unit\Container;

use Nitro\Container\Container;
use PHPUnit\Framework\TestCase;

/** Stand-in for a route-bound model. */
class BoundWidget
{
    public function __construct(public mixed $id) {}
}

/**
 * Exercises the parameter-binder seam that powers route-model binding: a typed
 * parameter whose name matches a scalar route value is handed to the binder,
 * which can resolve it to an object; declining falls through to normal logic.
 */
class ParameterBindingTest extends TestCase
{
    private function container(): Container
    {
        $c = new Container();
        $c->bindParametersUsing(function (string $type, mixed $value) {
            if ($type === BoundWidget::class) {
                return new BoundWidget($value);
            }
            return Container::PARAM_UNRESOLVED;
        });
        return $c;
    }

    public function test_typed_param_with_matching_route_value_is_bound_to_object(): void
    {
        $result = $this->container()->call(
            fn (BoundWidget $widget) => $widget,
            ['widget' => 42],
        );

        $this->assertInstanceOf(BoundWidget::class, $result);
        $this->assertSame(42, $result->id);
    }

    public function test_untyped_scalar_param_is_unaffected(): void
    {
        $result = $this->container()->call(
            fn ($id) => $id,
            ['id' => 7],
        );

        $this->assertSame(7, $result);
    }

    public function test_typed_param_without_a_matching_route_value_autowires(): void
    {
        // No primitive matches the param name, so the binder is never consulted
        // and normal auto-wiring resolves the dependency.
        $result = $this->container()->call(
            fn (Container $c) => $c,
        );

        $this->assertInstanceOf(Container::class, $result);
    }

    public function test_no_binder_registered_leaves_scalar_named_override(): void
    {
        $c = new Container(); // no binder
        $result = $c->call(fn ($slug) => $slug, ['slug' => 'hello']);
        $this->assertSame('hello', $result);
    }
}
