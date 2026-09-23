<?php

namespace Tests\Unit\Routing;

use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Request;
use Nitro\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What route() does to a parameter on its way into the path.
 *
 * A value is escaped so it cannot change the shape of the URL, but a reserved
 * character written on purpose has to arrive as written. The slash is the one
 * that matters: a {path} constrained with where('path', '.*') holds a path, and
 * escaping its separators produced a URL that no longer matched the route that
 * generated it — a link the application built and could not then answer.
 */
class RouteUrlEncodingTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $config = new class implements ConfigRepository {
            public function get(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'app.controllers_namespace' => 'App\\Controllers',
                    'app.debug' => true,
                    default => $default,
                };
            }

            public function set(string $key, mixed $value): void {}
            public function has(string $key): bool { return false; }
            public function all(): array { return []; }
        };

        $this->router = new Router($config);
        $this->router->get('/files/{path}', fn () => 'x')->name('files')->where('path', '.*');
        $this->router->get('/tags/{tag}', fn () => 'x')->name('tags.show');
    }

    /**
     * The set is Laravel's: / @ : ; , = + ! * | ? & # % reach the path as
     * themselves, so a generated link reads the way it was written.
     */
    #[DataProvider('reservedCharacters')]
    public function test_a_reserved_character_is_not_escaped(string $value, string $expected): void
    {
        $this->assertSame($expected, $this->router->route('tags.show', ['tag' => $value]));
    }

    public static function reservedCharacters(): array
    {
        return [
            'slash'     => ['a/b', '/tags/a/b'],
            'at'        => ['a@b', '/tags/a@b'],
            'colon'     => ['a:b', '/tags/a:b'],
            'semicolon' => ['a;b', '/tags/a;b'],
            'comma'     => ['a,b', '/tags/a,b'],
            'equals'    => ['a=b', '/tags/a=b'],
            'plus'      => ['a+b', '/tags/a+b'],
            'bang'      => ['a!b', '/tags/a!b'],
            'star'      => ['a*b', '/tags/a*b'],
            'pipe'      => ['a|b', '/tags/a|b'],
            'question'  => ['a?b', '/tags/a?b'],
            'ampersand' => ['a&b', '/tags/a&b'],
            'hash'      => ['a#b', '/tags/a#b'],
            'percent'   => ['a%b', '/tags/a%b'],
        ];
    }

    /** Everything outside that set still escapes, so a space cannot split a path. */
    public function test_an_unreserved_character_is_still_escaped(): void
    {
        $this->assertSame('/tags/a%20b', $this->router->route('tags.show', ['tag' => 'a b']));
        $this->assertSame('/tags/a%22b', $this->router->route('tags.show', ['tag' => 'a"b']));
    }

    /** The point of all of it: the URL has to match the route that built it. */
    public function test_a_generated_path_matches_its_own_route(): void
    {
        $url = $this->router->route('files', ['path' => 'reports/2026/q1.pdf']);

        $this->assertSame('/files/reports/2026/q1.pdf', $url);

        $route = $this->router->findMatchingRoute(new Request('GET', $url));

        $this->assertNotNull($route);
        $this->assertSame('files', $route->getName());
        $this->assertSame('reports/2026/q1.pdf', $route->getParameters()['path']);
    }
}
