<?php

namespace Nitro\Coroutine;

use RuntimeException;
use Throwable;

/**
 * A lock one coroutine holds at a time.
 *
 * Coroutines do not preempt each other, so a section that never yields needs
 * no lock at all. This is for the section that DOES yield — an await, a sleep,
 * an HTTP call — where another coroutine gets to run in the middle and must
 * not enter the same section.
 *
 *   $lock->run(function () {
 *       $total = $this->readTotal();
 *       Co::sleep(0.1);          // ← without the lock, another coroutine
 *       $this->writeTotal($total + 1);   //   reads the same stale total here
 *   });
 *
 * A waiter suspends rather than spins, so the scheduler keeps working. This
 * is not reentrant: the holder calling acquire() again deadlocks itself.
 */
class Lock
{
    /**
     * A rendezvous channel used only to hand the lock to a waiter.
     *
     * Built here but never pushed to here: a Channel only reaches the
     * scheduler when it is used, so a Lock can be a plain property on an
     * object constructed long before any coroutine exists.
     */
    private Channel $handoff;

    /** The coroutine currently holding it, or null. */
    private ?int $holder = null;

    /** Coroutines parked in acquire(), so release() knows whether to hand over. */
    private int $waiting = 0;

    public function __construct()
    {
        $this->handoff = new Channel(0);
    }

    /** Take the lock, suspending until it is free. */
    public function acquire(): void
    {
        while ($this->holder !== null) {
            $this->waiting++;
            $this->handoff->pop();
            $this->waiting--;
        }

        $this->holder = Co::id();
    }

    /**
     * Give it back, waking one waiter if any.
     *
     * @throws RuntimeException When nobody holds it — a release without a
     *         matching acquire hands a second coroutine the same section.
     */
    public function release(): void
    {
        if ($this->holder === null) {
            throw new RuntimeException('Cannot release a lock that is not held.');
        }

        $this->holder = null;

        /*
         * Only when somebody is parked: an unbuffered push with no receiver
         * suspends the releaser, which would strand the lock it just freed.
         */
        if ($this->waiting > 0) {
            $this->handoff->push(true);
        }
    }

    /** Whether anyone currently holds it. */
    public function isHeld(): bool
    {
        return $this->holder !== null;
    }

    /**
     * Run the callback holding the lock, and give it back whatever happens.
     *
     * The form to prefer: a section that throws still releases, where a bare
     * acquire()/release() pair strands the lock and hangs every waiter.
     */
    public function run(callable $callback): mixed
    {
        $this->acquire();

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }
}
