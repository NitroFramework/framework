<?php

namespace Nitro\Testing;

use Nitro\Container\Container;
use Nitro\Database\DB;
use Nitro\Foundation\Application;
use Nitro\Http\Kernel;
use Nitro\Http\Request;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * The base class for a test that needs the application.
 *
 * A request made here goes through the real kernel — real middleware, real
 * router, real views. That is the point: a test that assembles a controller by
 * hand and calls it proves the controller works and says nothing about whether
 * the page does, and the gap between those two is where the bugs that reach
 * production live. Middleware that was never registered, a route that was never
 * added, a view that references a variable the controller stopped passing:
 * none of them are visible to a test that skips the kernel.
 *
 *     class CatalogueTest extends TestCase
 *     {
 *         public function test_the_course_page_renders(): void
 *         {
 *             $this->get('/courses/food-hygiene')->assertOk()->assertSee('Food Hygiene');
 *         }
 *     }
 */
abstract class TestCase extends BaseTestCase
{
    protected ?Application $app = null;

    /** Headers sent with every request from this test. */
    protected array $defaultHeaders = [];

    /** Whether write requests carry a valid CSRF token. See request(). */
    protected bool $includeCsrfToken = true;

    /**
     * The application's base path. Override when the test suite does not sit
     * two directories under the project root.
     */
    protected function basePath(): string
    {
        return dirname((new \ReflectionClass(static::class))->getFileName(), 3);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshApplication();
        $this->setUpTraits();
    }

    /**
     * Run each trait's own setup hook — a trait named RefreshDatabase may
     * define setUpRefreshDatabase().
     *
     * The same convention as a model's boot hooks, and for the same reason: a
     * trait should be able to do its own wiring by being used, rather than
     * requiring every test that uses it to remember to call something.
     */
    protected function setUpTraits(): void
    {
        foreach (class_uses_recursive(static::class) as $trait) {
            $method = 'setUp' . class_basename($trait);

            if (method_exists($this, $method)) {
                $this->{$method}();
            }
        }
    }

    protected function tearDown(): void
    {
        $this->app = null;
        Container::reset();

        // Bootstrapping installs an error and an exception handler. PHPUnit
        // reports a test that leaves handlers behind as risky, and it is right
        // to: a handler left installed swallows the next test's failures.
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    /** Resolve something out of the application container. */
    protected function make(string $abstract): mixed
    {
        return $this->app->getContainer()->createOrResolve($abstract);
    }

    /**
     * Boot a fresh application for this test.
     *
     * Fresh per test, not shared: a container that survives between tests
     * carries singletons one test mutated into the next, and the resulting
     * failure appears in whichever test happens to run second.
     */
    protected function refreshApplication(): void
    {
        Container::reset();

        $this->app = new Application($this->basePath());
        $this->app->bootstrap();
    }

    // ─── Making requests ──────────────────────────────────

    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->request('GET', $uri, [], $headers);
    }

    public function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('POST', $uri, $data, $headers);
    }

    public function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('PUT', $uri, $data, $headers);
    }

    public function patch(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('PATCH', $uri, $data, $headers);
    }

    public function delete(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('DELETE', $uri, $data, $headers);
    }

    public function getJson(string $uri, array $headers = []): TestResponse
    {
        return $this->get($uri, $headers + ['Accept' => 'application/json']);
    }

    public function postJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->post($uri, $data, $headers + ['Accept' => 'application/json']);
    }

    /**
     * Build a request and send it through the kernel.
     *
     * The query string is split off the URI and put where a real request would
     * carry it, so ?page=2 reaches request()->query() rather than becoming part
     * of the path and matching no route.
     */
    protected function request(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $query = [];
        $path = $uri;

        if (str_contains($uri, '?')) {
            [$path, $queryString] = explode('?', $uri, 2);
            parse_str($queryString, $query);
        }

        // Present a valid CSRF token rather than exempting the test from the
        // middleware. The check still runs, so a route that should be protected
        // still is — and a test that wants to prove the protection works can
        // turn this off with withoutCsrfToken() and assert the 419.
        if ($this->includeCsrfToken && !in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $data['_token'] ??= \csrf_token();
        }

        $request = new Request(
            $method,
            '/' . ltrim($path, '/'),
            array_merge($this->defaultHeaders, $headers),
            $query,
            $data,
            [],
            ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri],
        );

        // Kernel::run() binds the request before handling it, and everything
        // that calls request() depends on that. handle() alone does not, so
        // without this a controller using the request() helper dies resolving
        // Request out of an empty container — which is nothing to do with the
        // code under test.
        $container = $this->app->getContainer();
        $container->instance('request', $request);
        $container->instance(Request::class, $request);

        $response = $this->make(Kernel::class)->handle($request);

        return new TestResponse($response);
    }

    /** Send these headers with every subsequent request from this test. */
    public function withHeaders(array $headers): static
    {
        $this->defaultHeaders = array_merge($this->defaultHeaders, $headers);

        return $this;
    }

    /**
     * Stop sending a CSRF token, so a write request arrives without one.
     *
     * For the test that proves a route is protected. Everything else wants the
     * token, because a suite that runs without CSRF is a suite in which nobody
     * ever notices the day the middleware stops being registered.
     */
    public function withoutCsrfToken(): static
    {
        $this->includeCsrfToken = false;

        return $this;
    }

    // ─── Authentication ───────────────────────────────────

    /**
     * Sign a user in for the rest of the test.
     *
     * Through the guard rather than by writing a session key directly, so that
     * whatever the guard does on login — regenerating the session id, recording
     * a timestamp — happens here too. A test that fakes the session tests a
     * login the application never performs.
     */
    public function actingAs(object $user): static
    {
        $this->make('auth')->login($user);

        return $this;
    }

    // ─── Database assertions ──────────────────────────────

    public function assertDatabaseHas(string $table, array $attributes): static
    {
        $count = $this->tableQuery($table, $attributes)->count();

        Assert::assertGreaterThan(
            0,
            $count,
            "Found no row in [{$table}] matching " . $this->describe($attributes)
        );

        return $this;
    }

    public function assertDatabaseMissing(string $table, array $attributes): static
    {
        $count = $this->tableQuery($table, $attributes)->count();

        Assert::assertSame(
            0,
            $count,
            "Found {$count} unexpected row(s) in [{$table}] matching " . $this->describe($attributes)
        );

        return $this;
    }

    public function assertDatabaseCount(string $table, int $expected): static
    {
        $count = DB::table($table)->count();

        Assert::assertSame($expected, $count, "Expected {$expected} row(s) in [{$table}], found {$count}.");

        return $this;
    }

    private function tableQuery(string $table, array $attributes)
    {
        $query = DB::table($table);

        foreach ($attributes as $column => $value) {
            if ($value === null) {
                $query->whereNull($column);
            } else {
                $query->where($column, $value);
            }
        }

        return $query;
    }

    private function describe(array $attributes): string
    {
        $parts = [];
        foreach ($attributes as $key => $value) {
            $parts[] = $key . '=' . var_export($value instanceof \BackedEnum ? $value->value : $value, true);
        }

        return '[' . implode(', ', $parts) . '].';
    }
}
