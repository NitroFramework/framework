<?php

namespace Tests\Unit\Livewire;

use Nitro\Http\Request;
use Nitro\Livewire\Routing\LivewireRouteType;
use Nitro\Livewire\Runtime\LivewireManager;
use Nitro\Routing\Route;
use PHPUnit\Framework\TestCase;

/**
 * A full-page component is given the route's parameters.
 *
 * Route::livewire('/posts/{post}', 'show-post') matched and then rendered the
 * component as if the URL had carried nothing, because the route type called
 * page() with the component name and no second argument — while page() had
 * accepted parameters all along. Every address under a parameterised route
 * therefore rendered the same page.
 */
class LivewireRouteParametersTest extends TestCase
{
    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    private array $rendered = [];

    private function routeType(): LivewireRouteType
    {
        $manager = new class ($this->rendered) extends LivewireManager {
            /** @param array<int, array{0: string, 1: array}> $rendered */
            public function __construct(private array &$rendered)
            {
            }

            public function page(string $name, array $params = []): string
            {
                $this->rendered[] = [$name, $params];

                return '<div>rendered</div>';
            }
        };

        return new LivewireRouteType(static fn (): LivewireManager => $manager);
    }

    /** @param array<string, mixed> $parameters */
    private function route(array $parameters = []): Route
    {
        return new Route('livewire', 'show-post', $parameters);
    }

    public function test_the_component_is_given_the_routes_parameters(): void
    {
        $this->routeType()->dispatch(
            $this->route(['post' => '42']),
            new Request('GET', '/posts/42'),
        );

        $this->assertSame([['show-post', ['post' => '42']]], $this->rendered);
    }

    public function test_several_parameters_all_arrive(): void
    {
        $this->routeType()->dispatch(
            $this->route(['team' => '7', 'post' => '42']),
            new Request('GET', '/teams/7/posts/42'),
        );

        $this->assertSame(['team' => '7', 'post' => '42'], $this->rendered[0][1]);
    }

    /** A route with no placeholders passes an empty list, not null. */
    public function test_a_route_without_parameters_passes_nothing(): void
    {
        $this->routeType()->dispatch(
            $this->route(),
            new Request('GET', '/guide'),
        );

        $this->assertSame([['show-post', []]], $this->rendered);
    }

    public function test_it_still_renders_a_response(): void
    {
        $response = $this->routeType()->dispatch(
            $this->route(['post' => '42']),
            new Request('GET', '/posts/42'),
        );

        $this->assertSame('<div>rendered</div>', $response->getContent());
    }
}
