<?php

namespace Tests\Thrust;

use Nitro\Container\Container;
use Nitro\Foundation\Application;
use Nitro\Thrust\Adapters\FrankenPhpAdapter;
use Nitro\Thrust\Adapters\SwooleAdapter;
use Nitro\Thrust\Contracts\WorkerAdapter;
use Nitro\Thrust\Runner;
use Nitro\Thrust\WorkerMode;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Which runtime a worker ends up driving, and that it can still be built.
 *
 * Every application has a public/worker.php that resolves the runner from the
 * container. Putting the runtime behind an interface broke exactly that — an
 * interface is not instantiable — and FrankenPHP refused to start with "worker
 * has not reached frankenphp_handle_request()", which names the symptom and not
 * the cause. A default on the constructor is what keeps those files working,
 * and it is worth a test because the next person to touch that signature will
 * not have a FrankenPHP binary to find out with.
 */
class RunnerAdapterTest extends TestCase
{
    private function adapterOf(Runner $runner): WorkerAdapter
    {
        return (new ReflectionClass($runner))->getProperty('adapter')->getValue($runner);
    }

    public function test_a_runner_built_with_only_an_application_drives_frankenphp(): void
    {
        $runner = new Runner(new Application(dirname(__DIR__, 2)));

        $this->assertInstanceOf(FrankenPhpAdapter::class, $this->adapterOf($runner));
    }

    /** The shape every generated worker.php uses. */
    public function test_the_container_can_still_build_one(): void
    {
        Container::setInstance($container = new Container());

        $container->instance(Application::class, new Application(dirname(__DIR__, 2)));

        $runner = $container->resolve(Runner::class);

        $this->assertInstanceOf(Runner::class, $runner);
        $this->assertInstanceOf(WorkerAdapter::class, $this->adapterOf($runner));
    }

    public function test_another_runtime_is_taken_when_given(): void
    {
        $runner = new Runner(
            new Application(dirname(__DIR__, 2)),
            new SwooleAdapter(),
            new WorkerMode(),
        );

        $this->assertInstanceOf(SwooleAdapter::class, $this->adapterOf($runner));
    }

    /** Both runtimes answer the contract, whichever one the platform has. */
    public function test_both_adapters_honour_the_contract(): void
    {
        foreach ([new FrankenPhpAdapter(), new SwooleAdapter()] as $adapter) {
            $this->assertInstanceOf(WorkerAdapter::class, $adapter);
            $this->assertIsBool($adapter->isAvailable());
            $this->assertNotSame('', $adapter->unavailableReason());
        }
    }
}
