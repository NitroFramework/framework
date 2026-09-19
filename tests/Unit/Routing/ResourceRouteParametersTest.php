<?php

namespace Tests\Unit\Routing;

use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Request;
use Nitro\Routing\RouteTypes;
use Nitro\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What Route::resource() names its member parameter, and which verbs update
 * answers.
 *
 * The parameter came from rtrim($name, 's'), so resource('addresses') gave
 * /addresses/{addre} and resource('statuses') gave {statuse}. And update was
 * registered for PUT alone, so a form spoofing PATCH — which is what a partial
 * update is — hit a route that did not exist.
 */
class ResourceRouteParametersTest extends TestCase
{
    private function router(): Router
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn('App\\Controllers\\');

        return new Router($config, new RouteTypes());
    }

    /** @return array<int, string> "VERB /path" for every registered route */
    private function registered(Router $router): array
    {
        $found = [];

        foreach ($router->getRoutes() as $verb => $paths) {
            foreach (array_keys($paths) as $path) {
                $found[] = "{$verb} {$path}";
            }
        }

        sort($found);

        return $found;
    }

    #[DataProvider('resourceNames')]
    public function test_the_member_parameter_is_a_real_singular(string $resource, string $parameter): void
    {
        $router = $this->router();
        $router->resource($resource, 'SomeController');

        $this->assertContains(
            "GET /{$resource}/{{$parameter}}",
            $this->registered($router),
            "resource('{$resource}') should take a {{$parameter}}",
        );
    }

    public static function resourceNames(): array
    {
        return [
            ['users', 'user'],
            ['posts', 'post'],
            ['categories', 'category'],
            ['addresses', 'address'],
            ['statuses', 'status'],
            ['companies', 'company'],
            ['boxes', 'box'],
        ];
    }

    public function test_update_answers_put_and_patch(): void
    {
        $router = $this->router();
        $router->resource('posts', 'PostController');

        $registered = $this->registered($router);

        $this->assertContains('PUT /posts/{post}', $registered);
        $this->assertContains('PATCH /posts/{post}', $registered);
    }

    /** Both verbs must actually route, not merely be in the table. */
    public function test_a_patch_request_reaches_the_update_action(): void
    {
        $router = $this->router();
        $router->resource('posts', 'PostController');

        foreach (['PUT', 'PATCH'] as $verb) {
            $route = $router->findMatchingRoute(new Request($verb, '/posts/7'));

            $this->assertNotNull($route, "{$verb} should reach the update action");
            $this->assertSame('update', $route->getControllerMethod());
            $this->assertSame('7', $route->getParameters()['post'] ?? null);
        }
    }

    /**
     * One name for the two verbs: route('posts.update') has to keep meaning
     * something, and a second registration under the same name would take it.
     */
    public function test_the_update_route_is_named_once(): void
    {
        $router = $this->router();
        $router->resource('posts', 'PostController');

        $named = $router->getRouteByName('posts.update');

        $this->assertNotNull($named);
        $this->assertSame('PUT', $named['method']);
        $this->assertSame('/posts/{post}', $named['path']);
    }

    /** An unhappy inflection is overridden rather than worked around. */
    public function test_the_parameter_can_be_named_explicitly(): void
    {
        $router = $this->router();
        $router->resource('media', 'MediaController', ['parameter' => 'item']);

        $this->assertContains('GET /media/{item}', $this->registered($router));
    }

    /** only/except still select the right actions. */
    public function test_only_still_limits_the_actions(): void
    {
        $router = $this->router();
        $router->resource('posts', 'PostController', ['only' => ['index', 'show']]);

        $this->assertSame(
            ['GET /posts', 'GET /posts/{post}'],
            $this->registered($router),
        );
    }
}
