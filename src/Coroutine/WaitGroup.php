<?php

namespace Nitro\Coroutine;

use BadMethodCallException;
use InvalidArgumentException;

/**
 * Wait for a set of coroutines to finish — the "join N of them" primitive.
 *
 *   $wg = new WaitGroup();
 *   $wg->add();  Co::go(function () use ($wg) { ...; $wg->done(); });
 *   $wg->wait();  // suspends until every done() has landed
 *
 * Pure PHP over a size-1 Channel: wait() pops (parking the caller) and the final
 * done() pushes to wake it.
 */
class WaitGroup
{
    private Channel $channel;

    private int $count = 0;

    private bool $waiting = false;

    public function __construct(int $delta = 0)
    {
        $this->channel = new Channel(1);
        if ($delta > 0) {
            $this->add($delta);
        }
    }

    public function add(int $delta = 1): void
    {
        if ($this->waiting) {
            throw new BadMethodCallException('WaitGroup: add() called while a wait() is in progress.');
        }

        $count = $this->count + $delta;
        if ($count < 0) {
            throw new InvalidArgumentException('WaitGroup: negative counter.');
        }

        $this->count = $count;
    }

    public function done(): void
    {
        $count = $this->count - 1;
        if ($count < 0) {
            throw new BadMethodCallException('WaitGroup: done() called more times than add().');
        }

        $this->count = $count;
        if ($count === 0 && $this->waiting) {
            $this->channel->push(true);
        }
    }

    public function wait(): void
    {
        if ($this->waiting) {
            throw new BadMethodCallException('WaitGroup: already being waited on.');
        }

        if ($this->count > 0) {
            $this->waiting = true;
            $this->channel->pop();
            $this->waiting = false;
        }
    }

    public function count(): int
    {
        return $this->count;
    }
}
