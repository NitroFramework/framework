<?php

namespace Nitro\Routing;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Throwable;

/**
 * Middleware pipeline with Illuminate\Routing\Pipeline semantics (exceptions are rendered at the
 * layer that threw them, Responsable results are converted), without building a closure onion
 * up front: each layer's $next closure is created only when that layer actually runs.
 *
 * Stack items are pre-parsed at compile time: [class|Closure|serialized closure, params, isClosure].
 */
final class Pipeline
{
    /** @var list<object> Middleware instances used, for terminate(). */
    public array $used = [];

    public function __construct(private readonly \Illuminate\Contracts\Container\Container $container)
    {
    }

    public function run(array $stack, Request $request, Closure $destination): mixed
    {
        return $this->layer($stack, 0, $request, $destination);
    }

    private function layer(array $stack, int $i, $request, Closure $destination): mixed
    {
        try {
            if (! isset($stack[$i])) {
                return self::carry($destination($request), $request);
            }

            [$middleware, $parameters, $isClosure] = $stack[$i];
            $next = fn ($request) => $this->layer($stack, $i + 1, $request, $destination);

            if ($isClosure) {
                if (is_string($middleware)) {
                    $middleware = unserialize($middleware)->getClosure();
                }

                return self::carry($middleware($request, $next, ...$parameters), $request);
            }

            $instance = $this->container->make($middleware);
            $this->used[] = $instance;

            return self::carry(
                method_exists($instance, 'handle')
                    ? $instance->handle($request, $next, ...$parameters)
                    : $instance($request, $next, ...$parameters),
                $request
            );
        } catch (Throwable $e) {
            return $this->handleException($request, $e);
        }
    }

    private static function carry(mixed $carry, $request): mixed
    {
        return $carry instanceof Responsable ? $carry->toResponse($request) : $carry;
    }

    private function handleException($request, Throwable $e): mixed
    {
        if (! $this->container->bound(ExceptionHandler::class) || ! $request instanceof Request) {
            throw $e;
        }

        $handler = $this->container->make(ExceptionHandler::class);

        $handler->report($e);

        $response = $handler->render($request, $e);

        if (is_object($response) && method_exists($response, 'withException')) {
            $response->withException($e);
        }

        return self::carry($response, $request);
    }

    /**
     * Parse "name:param1,param2" strings (kernel global middleware) into stack items.
     */
    public static function parse(array $middleware): array
    {
        $stack = [];

        foreach ($middleware as $item) {
            if ($item instanceof Closure) {
                $stack[] = [$item, [], true];
                continue;
            }

            [$name, $parameters] = array_pad(explode(':', $item, 2), 2, null);
            $stack[] = [$name, $parameters === null ? [] : explode(',', $parameters), false];
        }

        return $stack;
    }
}
