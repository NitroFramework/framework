<?php

namespace Tests\Thrust;

use Nitro\Container\Container;
use Nitro\Foundation\Contracts\ResetsBetweenRequests;
use Nitro\Thrust\Concerns\ResetsForWorkerMode;
use Nitro\Thrust\WorkerMode;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The worker clears per-request state without knowing which services hold any.
 *
 * The reset used to name PerformanceMetrics, CompiledTemplateCache and
 * ViewRenderer directly, which made it the one file that had to know about
 * every layer in the framework. A subsystem missing from that list kept serving
 * the previous request's state and said nothing — the failure mode these tests
 * exist to keep closed.
 */
class ResetsBetweenRequestsTest extends TestCase
{
    private Container $container;
    private WorkerHost $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        $this->host = new WorkerHost($this->container);
    }

    public function test_a_service_that_declares_itself_is_reset(): void
    {
        $this->container->singleton(Stateful::class);

        $service = $this->container->make(Stateful::class);
        $service->heldFromLastRequest = 'the previous visitor';

        $this->host->resetForWorkerMode(new WorkerMode());

        $this->assertNull($service->heldFromLastRequest);
    }

    public function test_a_service_that_declares_nothing_is_left_alone(): void
    {
        // The router, the config, a warm connection: keeping these is the whole
        // reason a worker is faster than FPM.
        $this->container->singleton(Persistent::class);

        $service = $this->container->make(Persistent::class);
        $service->compiledOnce = 'expensive';

        $this->host->resetForWorkerMode(new WorkerMode());

        $this->assertSame('expensive', $service->compiledOnce);
    }

    public function test_a_binding_nothing_asked_for_is_not_resolved(): void
    {
        // Resolving every binding to reset it would build the whole container
        // on every request — and a factory may open a connection or read the
        // request that no longer exists.
        $this->container->singleton(NeverAsk::class);

        $this->host->resetForWorkerMode(new WorkerMode());

        $this->assertFalse(NeverAsk::$built);
    }

    public function test_one_service_throwing_does_not_stop_the_others(): void
    {
        $this->container->singleton(Throws::class);
        $this->container->singleton(Stateful::class);

        $this->container->make(Throws::class);
        $service = $this->container->make(Stateful::class);
        $service->heldFromLastRequest = 'the previous visitor';

        // There is a next request to serve either way, and no request in scope
        // here to fail.
        $this->host->resetForWorkerMode(new WorkerMode());

        $this->assertNull($service->heldFromLastRequest);
    }

    public function test_the_shipped_view_services_declare_themselves(): void
    {
        // The two the reset used to name by hand. If either stops implementing
        // the contract, the worker silently stops clearing it.
        $this->assertTrue(
            is_subclass_of(\Nitro\View\Engine\ViewRenderer::class, ResetsBetweenRequests::class),
        );

        $this->assertTrue(
            is_subclass_of(\Nitro\View\Compiler\CompiledTemplateCache::class, ResetsBetweenRequests::class),
        );
    }
}

/** Stands in for an Application, which is where the trait really lives. */
class WorkerHost
{
    use ResetsForWorkerMode;

    public function __construct(protected Container $container)
    {
    }
}

class Stateful implements ResetsBetweenRequests
{
    public ?string $heldFromLastRequest = null;

    public function resetBetweenRequests(): void
    {
        $this->heldFromLastRequest = null;
    }
}

class Persistent
{
    public ?string $compiledOnce = null;
}

class NeverAsk implements ResetsBetweenRequests
{
    public static bool $built = false;

    public function __construct()
    {
        self::$built = true;
    }

    public function resetBetweenRequests(): void
    {
    }
}

class Throws implements ResetsBetweenRequests
{
    public function resetBetweenRequests(): void
    {
        throw new RuntimeException('cannot reset');
    }
}
