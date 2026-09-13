<?php

namespace Tests\Unit\Livewire;

use Nitro\Livewire\Runtime\LivewireManager;
use PHPUnit\Framework\TestCase;

/**
 * Middleware that must also run on the Livewire update endpoint.
 *
 * This is worth a test precisely because its absence is invisible. An update
 * posts to /livewire/update rather than to the route that rendered the page, so
 * the page's own route middleware does not run on it. A component that reads
 * ambient request state — an organisation resolved from the URL, say — renders
 * correctly on first paint and then refuses every button on the page, with
 * nothing in the console and nothing in the log.
 *
 * A component test cannot catch it either: those run no middleware at all and
 * set the context by hand. The registration itself is the only reachable proof.
 */
class PersistentMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        LivewireManager::flushPersistentMiddleware();
    }

    protected function tearDown(): void
    {
        LivewireManager::flushPersistentMiddleware();
        parent::tearDown();
    }

    public function test_registered_middleware_is_returned(): void
    {
        LivewireManager::addPersistentMiddleware([EnsureMember::class]);

        $this->assertSame([EnsureMember::class], LivewireManager::persistentMiddleware());
    }

    public function test_a_single_name_does_not_need_an_array(): void
    {
        LivewireManager::addPersistentMiddleware('organisation');

        $this->assertSame(['organisation'], LivewireManager::persistentMiddleware());
    }

    public function test_registering_twice_does_not_run_it_twice(): void
    {
        // Two providers both asking for the same guard is normal; the request
        // should not pay for it twice, and a middleware that regenerates a
        // session id or counts something would misbehave if it did.
        LivewireManager::addPersistentMiddleware([EnsureMember::class]);
        LivewireManager::addPersistentMiddleware([EnsureMember::class, 'auth']);

        $this->assertSame([EnsureMember::class, 'auth'], LivewireManager::persistentMiddleware());
    }

    public function test_the_list_starts_empty(): void
    {
        $this->assertSame([], LivewireManager::persistentMiddleware());
    }
}

class EnsureMember
{
}
