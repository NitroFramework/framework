<?php

namespace Nitro\Auth\Middleware;

use Nitro\Auth\Access\Gate;
use Nitro\Http\Request;
use Nitro\Http\Response;

/**
 * Checks an ability before the route runs.
 *
 *     Route::get('/posts/{post}/edit', ...)->middleware('can:update,post');
 *
 * Arguments after the ability name a route parameter, or a class name
 * for an ability about a type rather than a record — 'can:create,App\Post'.
 *
 * @throws \Nitro\Auth\Exceptions\AuthorizationException When it is denied.
 */
class Authorize
{
    public function __construct(protected Gate $gate) {}

    public function handle(Request $request, callable $next, string $ability, string ...$models): Response
    {
        $this->gate->authorize($ability, $this->argumentsFor($request, $models));

        return $next($request);
    }

    /**
     * Turn the middleware's arguments into what the ability expects.
     *
     * A name matching a route parameter is that parameter's value; a
     * name that is a class is the class itself, which is what an
     * ability about creating one takes.
     *
     * @param array<int, string> $models
     * @return array<int, mixed>
     */
    protected function argumentsFor(Request $request, array $models): array
    {
        return array_map(
            static function (string $model) use ($request): mixed {
                return $request->route($model) ?? $model;
            },
            $models,
        );
    }
}
