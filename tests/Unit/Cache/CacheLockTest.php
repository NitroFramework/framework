<?php

namespace Tests\Unit\Cache;

use Nitro\Cache\Drivers\ArrayStore;
use Nitro\Cache\Drivers\NullStore;
use Nitro\Cache\Exceptions\LockTimeoutException;
use Nitro\Cache\Repository;
use PHPUnit\Framework\TestCase;

/** Locks as objects rather than as a callback. */
class CacheLockTest extends TestCase
{
    private function repository(): Repository
    {
        return new Repository(new ArrayStore());
    }

    public function test_a_lock_is_granted_once(): void
    {
        $store = new ArrayStore();

        $this->assertTrue($store->lock('reports', 10)->acquire());
        $this->assertFalse($store->lock('reports', 10)->acquire(), 'a held lock must not be granted again');
    }

    public function test_a_released_lock_can_be_taken_again(): void
    {
        $store = new ArrayStore();

        $first = $store->lock('reports', 10);
        $first->acquire();
        $this->assertTrue($first->release());

        $this->assertTrue($store->lock('reports', 10)->acquire());
    }

    /** Releasing is scoped to the holder. */
    public function test_one_holder_cannot_release_another_holders_lock(): void
    {
        $store = new ArrayStore();

        $mine = $store->lock('reports', 10);
        $mine->acquire();

        $theirs = $store->lock('reports', 10);

        $this->assertFalse($theirs->release(), 'a lock this process does not hold must not be released');
        $this->assertFalse($store->lock('reports', 10)->acquire(), 'and the original holder still has it');
    }

    /** An operator clearing a stuck lock can, deliberately. */
    public function test_a_lock_can_be_force_released(): void
    {
        $store = new ArrayStore();

        $store->lock('reports', 10)->acquire();

        $store->lock('reports', 10)->forceRelease();

        $this->assertTrue($store->lock('reports', 10)->acquire());
    }

    /** A recorded token lets a later call release the same lock. */
    public function test_a_lock_can_be_restored_from_its_owner_token(): void
    {
        $store = new ArrayStore();

        $lock = $store->lock('reports', 10);
        $lock->acquire();

        $restored = $store->restoreLock('reports', $lock->owner());

        $this->assertTrue($restored->isOwnedByCurrentProcess());
        $this->assertTrue($restored->release());
    }

    // --- callback form ------------------------------------------------------

    public function test_get_runs_the_callback_while_holding_the_lock(): void
    {
        $store = new ArrayStore();

        $result = $store->lock('reports', 10)->get(static fn (): string => 'ran');

        $this->assertSame('ran', $result);
        $this->assertTrue($store->lock('reports', 10)->acquire(), 'the lock must be released afterwards');
    }

    public function test_get_returns_false_without_running_when_the_lock_is_held(): void
    {
        $store = new ArrayStore();
        $store->lock('reports', 10)->acquire();

        $ran = false;

        $result = $store->lock('reports', 10)->get(function () use (&$ran): string {
            $ran = true;

            return 'ran';
        });

        $this->assertFalse($result);
        $this->assertFalse($ran);
    }

    /** A throwing callback still gives the lock back. */
    public function test_the_lock_is_released_when_the_callback_throws(): void
    {
        $store = new ArrayStore();

        try {
            $store->lock('reports', 10)->get(static fn () => throw new \RuntimeException('boom'));
            $this->fail('the exception must propagate');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertTrue($store->lock('reports', 10)->acquire(), 'a failure must not hold the lock');
    }

    // --- blocking -----------------------------------------------------------

    public function test_blocking_gives_up_and_says_so(): void
    {
        $store = new ArrayStore();
        $store->lock('reports', 10)->acquire();

        $this->expectException(LockTimeoutException::class);

        $store->lock('reports', 10)
            ->betweenBlockedAttemptsSleepFor(10)
            ->block(0, static fn (): string => 'never');
    }

    public function test_blocking_succeeds_when_the_lock_is_free(): void
    {
        $store = new ArrayStore();

        $this->assertSame('ran', $store->lock('reports', 10)->block(1, static fn (): string => 'ran'));
    }

    // --- through the repository ---------------------------------------------

    public function test_the_repository_hands_out_locks(): void
    {
        $repository = $this->repository();

        $this->assertTrue($repository->lock('reports', 10)->acquire());
    }

    /** A store that cannot lock says so, rather than pretending. */
    public function test_a_store_without_locking_refuses_clearly(): void
    {
        $repository = new Repository(new class extends ArrayStore {
            public function lock(string $name, int $seconds = 0, ?string $owner = null): never
            {
                throw new \BadMethodCallException('nope');
            }
        });

        $this->expectException(\BadMethodCallException::class);

        $repository->lock('reports', 10);
    }

    /** The null store grants every lock. */
    public function test_the_null_store_never_blocks_anything(): void
    {
        $store = new NullStore();

        $this->assertTrue($store->lock('reports', 10)->acquire());
        $this->assertTrue($store->lock('reports', 10)->acquire());
        $this->assertSame('ran', $store->lock('reports', 10)->get(static fn (): string => 'ran'));
    }
}
