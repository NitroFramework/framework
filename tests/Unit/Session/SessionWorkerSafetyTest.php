<?php

namespace Tests\Unit\Session;

use Nitro\Container\Container;
use Nitro\Foundation\Config;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\PathRegistry;
use Nitro\Session\SessionServiceProvider;
use Nitro\Session\NativeSession;
use Nitro\Session\Store;
use Nitro\Thrust\WorkerMode;
use PHPUnit\Framework\TestCase;

/**
 * The native session driver relies on ext/session process globals that
 * FrankenPHP doesn't tear down between worker iterations — a per-request memory
 * leak. Under Thrust/worker mode the provider must transparently swap native
 * for the worker-safe file Store, while leaving native intact for FPM/serve.
 */
class SessionWorkerSafetyTest extends TestCase
{
    private function paths(): PathRegistry
    {
        return new class extends PathRegistry {
            public function __construct() {}
            public function storage(string $path = ''): string
            {
                return sys_get_temp_dir() . '/nitro-sess-test/' . $path;
            }
        };
    }

    private function container(bool $workerMode): Container
    {
        Container::setInstance(new Container());
        $container = Container::getInstance();

        // All three names, as LoadConfiguration binds them at boot.
        $config = Config::fromArray([
            'session' => ['driver' => 'native', 'cookie' => 'test_sess'],
        ]);
        $container->instance('config', $config);
        $container->instance(Config::class, $config);
        $container->instance(ConfigRepository::class, $config);

        $container->instance('paths', $this->paths());

        if ($workerMode) {
            $container->instance(WorkerMode::class, new WorkerMode());
        }

        (new SessionServiceProvider($container))->register();

        return $container;
    }

    protected function tearDown(): void
    {
        Container::setInstance(new Container());
    }

    public function test_native_driver_is_kept_outside_worker_mode(): void
    {
        $session = $this->container(workerMode: false)->resolve('session');
        $this->assertInstanceOf(NativeSession::class, $session);
    }

    public function test_worker_mode_swaps_native_for_the_worker_safe_store(): void
    {
        $session = $this->container(workerMode: true)->resolve('session');

        $this->assertNotInstanceOf(
            NativeSession::class,
            $session,
            'native must not be used under worker mode (ext/session leaks per request)'
        );
        $this->assertInstanceOf(Store::class, $session);
    }
}
