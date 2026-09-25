<?php

namespace Nitro\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Nitro\Routing\CompiledRoutes;
use Nitro\Routing\RouteCompiler;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Nitro's compiled matcher against Laravel's own RouteCollection::match(), on randomly generated
 * route tables (static, dynamic, optional, constrained, overlapping, multi-method, fallback),
 * compiled in memory and through the exported cache file. Any difference is a bug.
 */
class RouteMatcherDifferentialTest extends TestCase
{
    private const SEGMENTS = ['users', 'posts', 'a', 'b', 'admin', 'api', 'v1', 'files'];

    public function test_matches_exactly_like_laravel(): void
    {
        for ($seed = 1; $seed <= 60; $seed++) {
            mt_srand($seed);

            [$router, $paths] = $this->randomRouter(mt_rand(5, 90));

            $inMemory = CompiledRoutes::fromCollection($router->getRoutes(), $router);
            $exported = $this->fromExport($router);

            foreach ($paths as $path) {
                foreach (['GET', 'POST', 'DELETE', 'OPTIONS'] as $method) {
                    $host = ['http://localhost', 'https://localhost', 'http://acme.example.com', 'https://beta.example.com'][mt_rand(0, 3)];
                    $request = Request::create($host.$path, $method);
                    $expected = $this->laravel($router, $request);

                    $this->assertSame($expected, $this->nitro($inMemory, $request), "seed {$seed}: {$method} {$path} (in memory)");
                    $this->assertSame($expected, $this->nitro($exported, $request), "seed {$seed}: {$method} {$path} (cached)");
                }
            }
        }
    }

    private function randomRouter(int $count): array
    {
        $router = new Router(new Dispatcher, new Container);
        $paths = ['/', '/nothing/here'];

        for ($i = 0; $i < $count; $i++) {
            $parts = [];
            $concrete = [];

            for ($depth = mt_rand(1, 3), $d = 0; $d < $depth; $d++) {
                $roll = mt_rand(1, 10);

                if ($roll <= 5) {
                    $parts[] = $s = self::SEGMENTS[array_rand(self::SEGMENTS)];
                    $concrete[] = $s;
                } elseif ($roll <= 8) {
                    $parts[] = '{p'.$d.'}';
                    $concrete[] = (string) mt_rand(1, 50);
                } else {
                    $parts[] = '{o'.$d.'?}';
                    $concrete[] = 'x'.mt_rand(1, 9);
                    break; // optional parameters only at the end
                }
            }

            $uri = implode('/', $parts);
            $methods = [['GET'], ['POST'], ['GET', 'POST'], ['DELETE']][mt_rand(0, 3)];
            $action = ['uses' => fn () => null, 'as' => 'r'.$i];
            $roll = mt_rand(1, 10);

            if ($roll === 1) {
                $action['https'] = true;
            } elseif ($roll === 2) {
                $action['domain'] = '{tenant}.example.com';
            }

            $route = $router->match($methods, $uri, $action);

            if (str_contains($uri, '{p0}') && mt_rand(0, 1)) {
                $route->where('p0', '[0-9]+');
            }

            $paths[] = '/'.implode('/', $concrete);
            $paths[] = '/'.implode('/', array_slice($concrete, 0, -1));
            $paths[] = '/'.implode('/', $concrete).'/';
            $paths[] = '/'.str_replace(['1', '2'], ['z', 'y'], implode('/', $concrete));
        }

        if (mt_rand(0, 1)) {
            $router->fallback(['uses' => fn () => null, 'as' => 'fallback']);
        }

        return [$router, array_values(array_unique($paths))];
    }

    private function fromExport(Router $router): CompiledRoutes
    {
        $file = tempnam(sys_get_temp_dir(), 'routes').'.php';
        file_put_contents($file, RouteCompiler::export(RouteCompiler::compile($router->getRoutes(), $router, true)));

        try {
            return CompiledRoutes::fromFile($file, $router);
        } finally {
            @unlink($file);
        }
    }

    private function laravel(Router $router, Request $request): string
    {
        try {
            $route = $router->getRoutes()->match($request);

            return $route->getName().' '.json_encode($route->parameters());
        } catch (HttpException $e) {
            return 'http '.$e->getStatusCode();
        } catch (Throwable $e) {
            return get_class($e);
        }
    }

    private function nitro(CompiledRoutes $routes, Request $request): string
    {
        try {
            [$route] = $routes->find($request);

            return $route->getName().' '.json_encode($route->parameters());
        } catch (HttpException $e) {
            return 'http '.$e->getStatusCode();
        } catch (Throwable $e) {
            return get_class($e);
        }
    }
}
