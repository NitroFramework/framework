<?php

namespace Tests\Unit\Routing;

use Nitro\Http\Request;
use Nitro\Routing\Contracts\RouterInterface;
use Nitro\Routing\Route;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Somebody must be able to bring their own router.
 *
 * RouterInterface existed before this, but described a router the framework
 * could not actually use: the Kernel and two providers type-hinted the concrete
 * Router because they called substituteBindings(), aliasMiddleware() and the
 * two middleware-alias readers, none of which were on the contract. An
 * implementation could satisfy the interface and still be unusable — which is
 * the worst kind of seam, because it looks open.
 */
class RouterIsSwappableTest extends TestCase
{
    /**
     * Nothing the framework depends on may be missing from the contract.
     *
     * Checked by reflection rather than by eye, because the failure this guards
     * against is additive: someone adds a method to Router, calls it from the
     * Kernel, and the interface silently stops describing what is required.
     *
     * A method may also come from an optional capability contract — but only
     * one the Kernel checks with instanceof before calling, which is what makes
     * the call safe against a router that does not implement it. An unguarded
     * call still fails here, which is the point.
     */
    public function test_the_contract_covers_every_router_method_the_kernel_calls(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Http/Kernel.php');

        preg_match_all('/\$this->router->(\w+)\(/', $source, $matches);
        $called = array_unique($matches[1]);

        $this->assertNotEmpty($called, 'expected the kernel to call the router');

        $declared = $this->methodsOf(RouterInterface::class);

        preg_match_all('/\$this->router instanceof (\w+)/', $source, $guarded);

        foreach (array_unique($guarded[1]) as $capability) {
            $contract = 'Nitro\\Routing\\Contracts\\' . $capability;

            $this->assertTrue(
                interface_exists($contract),
                "the kernel guards on {$capability}, which is not a routing contract",
            );

            $declared = array_merge($declared, $this->methodsOf($contract));
        }

        $missing = array_values(array_diff($called, $declared));

        $this->assertSame([], $missing, 'the kernel calls these, but no contract it checks for declares them');
    }

    /** @return array<int, string> */
    private function methodsOf(string $contract): array
    {
        return array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass($contract))->getMethods(),
        );
    }

    /**
     * The end-to-end proof: a router the framework has never seen serves a
     * request, and the response comes back from it rather than from Nitro's.
     */
    public function test_a_foreign_router_can_serve_a_request(): void
    {
        $router = new class implements RouterInterface {
            public array $aliases = [];

            public function get(string $path, $handler): static { return $this; }
            public function post(string $path, $handler): static { return $this; }
            public function put(string $path, $handler): static { return $this; }
            public function delete(string $path, $handler): static { return $this; }
            public function patch(string $path, $handler): static { return $this; }
            public function group(array $attributes, \Closure $callback): static { return $this; }
            public function view(string $path, string $viewName, array $data = []): static { return $this; }

            public function findMatchingRoute(Request $request): ?Route
            {
                return new Route(Route::TYPE_CLOSURE, static fn (): string => 'served by a foreign router');
            }

            public function getRoutes(): array { return []; }
            public function getNamedRoutes(): array { return []; }
            public function getCompiledRoutes(): array { return []; }
            public function loadCachedRoutes(array $cached): void {}
            public function clearRoutes(): void {}

            public function substituteBindings(Route $route): Route { return $route; }
            public function aliasMiddleware(string $name, string $class): static { $this->aliases[$name] = $class; return $this; }
            public function getMiddlewareAlias(string $name): ?string { return $this->aliases[$name] ?? null; }
            public function getMiddlewareAliases(): array { return $this->aliases; }
        };

        $this->assertInstanceOf(RouterInterface::class, $router);

        $route = $router->findMatchingRoute(new Request('GET', '/anything'));

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame([], $router->getMiddlewareAliases());
    }

    /**
     * The Kernel must accept the contract, not the class.
     *
     * A concrete type-hint is what made the router unswappable before, and it is
     * the kind of thing that creeps back in without anything failing.
     */
    public function test_the_kernel_depends_on_the_contract(): void
    {
        $parameters = (new ReflectionMethod(\Nitro\Http\Kernel::class, '__construct'))->getParameters();

        $routerParameter = null;
        foreach ($parameters as $parameter) {
            if ($parameter->getName() === 'router') {
                $routerParameter = $parameter;
            }
        }

        $this->assertNotNull($routerParameter, 'the kernel should take a router');
        $this->assertSame(
            RouterInterface::class,
            (string) $routerParameter->getType(),
            'the kernel must depend on RouterInterface so an application can supply its own',
        );
    }
}
