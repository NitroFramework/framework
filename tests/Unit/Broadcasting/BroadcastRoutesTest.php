<?php

namespace Tests\Unit\Broadcasting;

use Nitro\Broadcasting\BroadcastManager;
use Nitro\Broadcasting\BroadcastServiceProvider;
use Nitro\Container\Container;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Request;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * The authorisation endpoint, served when the application asks for it.
 *
 * The provider registered the route at boot, which meant booting it on every
 * request, including those from applications that never broadcast. It defers
 * again, and Broadcast::routes() registers the route instead.
 */
class BroadcastRoutesTest extends TestCase
{
    private Container $container;

    /** @var array<string, mixed> */
    private array $config = ['app.controllers_namespace' => 'App\\Controllers'];

    protected function setUp(): void
    {
        parent::setUp();

        Container::reset();

        $this->container = new Container();

        Container::setInstance($this->container);

        $config = &$this->config;

        $this->container->instance(ConfigRepository::class, new class ($config) implements ConfigRepository {
            public function __construct(private array &$values) {}
            public function has(string $key): bool { return array_key_exists($key, $this->values); }
            public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
            public function all(): array { return $this->values; }
            public function set(string $key, mixed $value): void { $this->values[$key] = $value; }
        });

        $this->container->instance(Router::class, new Router($this->container->resolve(ConfigRepository::class)));
    }

    protected function tearDown(): void
    {
        Container::setInstance(new Container());

        parent::tearDown();
    }

    public function test_the_provider_defers_until_the_manager_is_asked_for(): void
    {
        $provider = new BroadcastServiceProvider($this->container);

        $this->assertTrue($provider->isDeferred());
        $this->assertSame([BroadcastManager::class], $provider->provides());
    }

    public function test_routes_serves_the_authorisation_endpoint(): void
    {
        $this->manager()->routes();

        $this->assertNotNull($this->router()->findMatchingRoute(new Request('POST', '/broadcasting/auth')));
    }

    public function test_the_endpoint_is_absent_until_routes_is_called(): void
    {
        $this->manager();

        $this->assertNull($this->router()->findMatchingRoute(new Request('POST', '/broadcasting/auth')));
    }

    public function test_the_endpoint_moves_with_its_configured_path(): void
    {
        $this->config['broadcasting.auth_path'] = '/sockets/auth';

        $this->manager()->routes();

        $this->assertNotNull($this->router()->findMatchingRoute(new Request('POST', '/sockets/auth')));
        $this->assertNull($this->router()->findMatchingRoute(new Request('POST', '/broadcasting/auth')));
    }

    private function manager(): BroadcastManager
    {
        return new BroadcastManager(
            $this->container->resolve(ClassResolver::class),
            $this->container->resolve(ConfigRepository::class),
        );
    }

    private function router(): Router
    {
        return $this->container->resolve(Router::class);
    }
}
