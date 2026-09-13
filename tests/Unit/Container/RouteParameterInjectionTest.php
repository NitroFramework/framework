<?php

namespace Tests\Unit\Container;

use Nitro\Container\Container;
use PHPUnit\Framework\TestCase;

/** A service a controller asks the container for. */
class Renderer
{
    public function name(): string
    {
        return 'renderer';
    }
}

/** Stand-in for a model bound from a route segment. */
class BoundReport
{
    public function __construct(public mixed $id) {}
}

/**
 * A controller action takes route segments and dependencies in the same
 * signature.
 *
 * Route values arrive positionally as well as by name, and a class-typed
 * parameter used to consume whichever position it happened to sit at — so
 * __invoke(string $code, Renderer $renderer) was handed the route's second
 * value as its renderer and died on a type error. The rule is that a
 * class-typed parameter is a dependency unless the binder can turn the value
 * into that class.
 */
class RouteParameterInjectionTest extends TestCase
{
    private function container(): Container
    {
        $container = new Container();

        $container->bindParametersUsing(function (string $type, mixed $value) {
            return $type === BoundReport::class ? new BoundReport($value) : Container::PARAM_UNRESOLVED;
        });

        return $container;
    }

    public function test_a_dependency_after_a_route_parameter_is_resolved_not_filled(): void
    {
        $result = $this->container()->call(
            fn (string $code, Renderer $renderer) => $code . ':' . $renderer->name(),
            ['code' => 'LP-7K42', 0 => 'LP-7K42'],
        );

        $this->assertSame('LP-7K42:renderer', $result);
    }

    public function test_a_dependency_before_a_route_parameter_is_resolved_too(): void
    {
        // The route value is still the first positional one, and must reach the
        // scalar rather than being swallowed by the dependency in front of it.
        $result = $this->container()->call(
            fn (Renderer $renderer, string $code) => $renderer->name() . ':' . $code,
            [0 => 'LP-7K42'],
        );

        $this->assertSame('renderer:LP-7K42', $result);
    }

    public function test_two_route_parameters_still_arrive_in_order(): void
    {
        $result = $this->container()->call(
            fn (string $course, string $lesson, Renderer $renderer) => "{$course}/{$lesson}/{$renderer->name()}",
            [0 => 'food-safety', 1 => 'allergens'],
        );

        $this->assertSame('food-safety/allergens/renderer', $result);
    }

    public function test_a_positional_value_the_binder_claims_still_becomes_a_model(): void
    {
        // Route-model binding by position, for a parameter whose name does not
        // match the segment's.
        $result = $this->container()->call(
            fn (BoundReport $report) => $report->id,
            [0 => 42],
        );

        $this->assertSame(42, $result);
    }

    public function test_an_object_passed_positionally_is_still_used(): void
    {
        $renderer = new Renderer();

        $result = $this->container()->call(
            fn (Renderer $given) => $given,
            [0 => $renderer],
        );

        $this->assertSame($renderer, $result);
    }
}
