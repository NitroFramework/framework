<?php

namespace Tests\Unit\Session;

use Nitro\Container\Container;
use Nitro\Foundation\Config;
use Nitro\Foundation\PathRegistry;
use Nitro\Foundation\Providers\SessionServiceProvider;
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
        Container::reset();
        $c = Container::getInstance();
        $c->instance('config', Config::fromArray([
            'session' => ['driver' => 'native', 'cookie' => 'test_sess'],
        ]));
        $c->instance('paths', $this->paths());
        if ($workerMode) {
            $c->instance(WorkerMode::class, new WorkerMode());
        }
        (new SessionServiceProvider($c))->register();
        return $c;
    }

    protected function tearDown(): void
    {
        Container::reset();
    }

    public function test_native_driver_is_kept_outside_worker_mode(): void
    {
        $session = $this->container(workerMode: false)->make('session');
        $this->assertInstanceOf(NativeSession::class, $session);
    }

    public function test_worker_mode_swaps_native_for_the_worker_safe_store(): void
    {
        $session = $this->container(workerMode: true)->make('session');

        $this->assertNotInstanceOf(
            NativeSession::class,
            $session,
            'native must not be used under worker mode (ext/session leaks per request)'
        );
        $this->assertInstanceOf(Store::class, $session);
    }
}
