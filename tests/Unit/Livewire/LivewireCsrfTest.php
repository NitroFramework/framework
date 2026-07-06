<?php

namespace Tests\Unit\Livewire;

use Nitro\Container\Container;
use Nitro\Foundation\Application;
use Nitro\Foundation\Http\Kernel;
use Nitro\Http\Middleware\VerifyCsrfToken;
use Nitro\Livewire\Checksum;
use Nitro\Livewire\LivewireManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Security tests for the Livewire commit surface.
 *
 *  1. CSRF: the /livewire/update and /livewire/upload endpoints must sit behind
 *     the 'web' middleware group, whose CSRF middleware verifies the
 *     X-CSRF-TOKEN header livewire.js sends. Without this an attacker holding a
 *     public component's (valid, unforgeable) snapshot could still drive method
 *     calls cross-site.
 *  2. Integrity: a tampered snapshot must be rejected by update() (checksum).
 */
class LivewireCsrfTest extends TestCase
{
    private static bool $bootstrapped = false;

    public static function setUpBeforeClass(): void
    {
        if (! self::$bootstrapped) {
            require_once __DIR__ . '/../../../vendor/autoload.php';
            Application::create(dirname(__DIR__, 3))->bootstrap();
            self::$bootstrapped = true;
        }
    }

    private function routeMiddleware(string $method, string $path): array
    {
        $routes = Container::getInstance()->make('router')->getRoutes();
        return $routes[$method][$path]['middleware'] ?? [];
    }

    public function test_update_endpoint_is_behind_web_group(): void
    {
        $path = config('livewire.update_uri', '/livewire/update');
        $this->assertContains('web', $this->routeMiddleware('POST', $path));
    }

    public function test_upload_endpoint_is_behind_web_group(): void
    {
        $this->assertContains('web', $this->routeMiddleware('POST', '/livewire/upload'));
    }

    public function test_web_group_resolves_to_csrf_middleware(): void
    {
        // The 'web' group the routes rely on must actually contain the CSRF
        // middleware, otherwise the wiring above would be a no-op.
        $defaults = (new ReflectionClass(Kernel::class))->getDefaultProperties();
        $this->assertContains(VerifyCsrfToken::class, $defaults['middlewareGroups']['web']);
    }

    public function test_update_rejects_tampered_snapshot(): void
    {
        /** @var LivewireManager $lw */
        $lw = Container::getInstance()->make('livewire');

        // Sign a snapshot with the same key the manager uses, then tamper it.
        $signer = new Checksum((string) (config('app.key') ?: 'livewire-insecure-dev-key'));
        $data = ['count' => 1];
        $memo = ['id' => 'abc123', 'name' => 'counter'];
        $snapshot = ['data' => $data, 'memo' => $memo, 'checksum' => $signer->generate($data, $memo)];

        $snapshot['data']['count'] = 999999; // attacker mutates protected state

        $this->expectException(RuntimeException::class);
        $lw->update(['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]]]);
    }
}
