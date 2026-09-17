<?php

namespace Tests\Container;

use Nitro\Container\Container;
use Nitro\Container\Exceptions\CapturedRequestStateException;
use Nitro\Thrust\Concerns\ResetsForWorkerMode;
use Nitro\Thrust\WorkerMode;
use PHPUnit\Framework\TestCase;

/**
 * Catching a capture made after construction.
 *
 * The resolution check reads constructors, and the lifetimes:check command
 * reads constructors and factory bodies. Neither can see a singleton that takes
 * nothing, asks the container for the session inside a method and keeps what it
 * got — which is the same defect with no declaration anywhere to give it away.
 * This is the only view of it short of a customer seeing another customer's
 * data.
 */
class CapturedStateTest extends TestCase
{
    private Container $container;
    private CaptureHost $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        $this->container->detectCapturedState(true);
        $this->container->scoped('session', fn () => new SessionState());

        $this->host = new CaptureHost($this->container);
    }

    public function test_a_singleton_that_keeps_the_session_is_caught(): void
    {
        $this->container->singleton(Keeper::class);

        $keeper = $this->container->make(Keeper::class);
        $this->container->instance(Keeper::class, $keeper);

        // Nothing in the wiring says this happens: the constructor is empty and
        // the binding is an ordinary singleton.
        $keeper->rememberTheSession($this->container);

        $this->expectException(CapturedRequestStateException::class);

        $this->host->resetForWorkerMode(new WorkerMode());
    }

    public function test_the_failure_names_where_it_is_held(): void
    {
        $this->container->singleton(Keeper::class);

        $keeper = $this->container->make(Keeper::class);
        $this->container->instance(Keeper::class, $keeper);
        $keeper->rememberTheSession($this->container);

        try {
            $this->host->resetForWorkerMode(new WorkerMode());
            $this->fail('Expected the captured state to be reported.');
        } catch (CapturedRequestStateException $exception) {
            // The property, not only the class: the holder is often not the
            // class that did the capturing.
            $this->assertStringContainsString('->session', $exception->getMessage());
            $this->assertStringContainsString(SessionState::class, $exception->getMessage());
        }
    }

    public function test_a_capture_one_object_deep_is_found(): void
    {
        // The realistic shape: a singleton holds a collaborator, and the
        // collaborator is what kept the request-scoped object.
        $this->container->singleton(Owner::class);

        $owner = $this->container->make(Owner::class);
        $this->container->instance(Owner::class, $owner);
        $owner->keeper->rememberTheSession($this->container);

        $this->expectException(CapturedRequestStateException::class);

        $this->host->resetForWorkerMode(new WorkerMode());
    }

    public function test_a_singleton_holding_nothing_of_the_request_passes(): void
    {
        $this->container->singleton(Keeper::class);
        $this->container->instance(Keeper::class, $this->container->make(Keeper::class));

        $this->host->resetForWorkerMode(new WorkerMode());

        $this->assertTrue(true, 'The reset completed without reporting a capture.');
    }

    public function test_last_requests_objects_are_not_reported_against_this_one(): void
    {
        $this->container->singleton(Keeper::class);

        $keeper = $this->container->make(Keeper::class);
        $this->container->instance(Keeper::class, $keeper);

        // Held from request one, and still held — but request one is over, so
        // this must not be reported again on every request thereafter. The
        // generation is what keeps a finding attached to the request it belongs
        // to rather than repeating for the life of the worker.
        $keeper->rememberTheSession($this->container);

        try {
            $this->host->resetForWorkerMode(new WorkerMode());
        } catch (CapturedRequestStateException) {
            // Reported once, against the request it happened in.
        }

        $this->host->resetForWorkerMode(new WorkerMode());

        $this->assertTrue(true, 'The second reset reported nothing.');
    }

    public function test_detection_is_off_unless_asked_for(): void
    {
        // It keeps a reference to every request-scoped object and reflects over
        // the whole long-lived graph; production pays neither.
        $container = new Container();
        $container->scoped('session', fn () => new SessionState());
        $container->singleton(Keeper::class);

        $keeper = $container->make(Keeper::class);
        $container->instance(Keeper::class, $keeper);
        $keeper->rememberTheSession($container);

        (new CaptureHost($container))->resetForWorkerMode(new WorkerMode());

        $this->assertSame([], $container->capturedRequestState());
    }
}

/** Stands in for an Application, which is where the trait really lives. */
class CaptureHost
{
    use ResetsForWorkerMode;

    public function __construct(protected Container $container)
    {
    }
}

class SessionState
{
    public array $data = ['basket' => ['course-1']];
}

class Keeper
{
    public ?SessionState $session = null;

    public function rememberTheSession(Container $container): void
    {
        $this->session = $container->get('session');
    }
}

class Owner
{
    public Keeper $keeper;

    public function __construct()
    {
        $this->keeper = new Keeper();
    }
}
