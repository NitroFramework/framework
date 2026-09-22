<?php

namespace Tests\Unit\Container;

use Nitro\Container\Container;
use Nitro\Container\Contracts\CallableInvoker;
use PHPUnit\Framework\TestCase;

/**
 * The parameter binder is told which parameter it is resolving.
 *
 * Route-model binding needs the name, because a route may declare the column
 * to bind by — "{post:slug}" — and only the name says which parameter that
 * applies to. The binder gets called from two places in resolveDependencies():
 * once when a route parameter matches the argument by name, once when it
 * matches positionally. Both must pass it.
 *
 * Only one of them did. Binding by a custom key worked in every unit test that
 * built a Route by hand and 404'd against a real application, because the
 * name-matched path is the one a real request takes.
 */
class ParameterBinderNameTest extends TestCase
{
    /** The arguments the binder was handed, per call. */
    private array $calls = [];

    private function invoker(): CallableInvoker
    {
        $container = new Container();

        $this->calls = [];

        $invoker = $container->get(CallableInvoker::class);

        $invoker->bindParametersUsing(function (string $type, mixed $value, string $name = '') {
            $this->calls[] = ['type' => $type, 'value' => $value, 'name' => $name];

            return new BoundThing($value, $name);
        });

        return $invoker;
    }

    /** The path a real request takes: the route parameter is keyed by name. */
    public function test_the_name_reaches_the_binder_when_matched_by_name(): void
    {
        $thing = $this->invoker()->call(
            static fn (BoundThing $post): BoundThing => $post,
            ['post' => 'hello-world'],
        );

        $this->assertSame('hello-world', $thing->value);
        $this->assertSame('post', $thing->name, 'the binder was not told which parameter it was resolving');
        $this->assertSame([['type' => BoundThing::class, 'value' => 'hello-world', 'name' => 'post']], $this->calls);
    }

    /** And the positional path, which a handler with mismatched names takes. */
    public function test_the_name_reaches_the_binder_when_matched_positionally(): void
    {
        $thing = $this->invoker()->call(
            static fn (BoundThing $post): BoundThing => $post,
            [0 => 'hello-world'],
        );

        $this->assertSame('hello-world', $thing->value);
        $this->assertSame('post', $thing->name);
    }

    /** A binder written for the old two-argument signature still works. */
    public function test_a_two_argument_binder_is_unaffected(): void
    {
        $container = new Container();

        $invoker = $container->get(CallableInvoker::class);

        $invoker->bindParametersUsing(
            static fn (string $type, mixed $value): BoundThing => new BoundThing($value, 'ignored')
        );

        $thing = $invoker->call(
            static fn (BoundThing $post): BoundThing => $post,
            ['post' => 'hello-world'],
        );

        $this->assertSame('hello-world', $thing->value);
    }

    /** Declining still falls through rather than binding null. */
    public function test_declining_falls_through(): void
    {
        $container = new Container();

        $invoker = $container->get(CallableInvoker::class);

        $invoker->bindParametersUsing(
            static fn (string $type, mixed $value, string $name = ''): mixed => CallableInvoker::PARAM_UNRESOLVED
        );

        $result = $invoker->call(
            static fn (string $post): string => $post,
            ['post' => 'hello-world'],
        );

        $this->assertSame('hello-world', $result);
    }
}

/** A stand-in for a route-bound model. */
class BoundThing
{
    public function __construct(
        public mixed $value,
        public string $name,
    ) {}
}
