<?php

namespace Nitro\Coroutine;

use Fiber;
use Throwable;

/**
 * One coroutine — a unit of work the Scheduler can pause and resume.
 *
 * Under the hood this is a native PHP Fiber (no Swoole/Swow). The Scheduler drives
 * it: each tick() runs the fiber until it either finishes or SUSPENDS with an
 * instruction ("sleep until T", "wait on this curl handle", "park me"). The
 * scheduler reads that instruction, arranges the wake-up, and ticks it again later.
 *
 * The callable's return value and any thrown exception are captured onto the
 * instance so a joiner can read them via Co::await() — the fiber body never throws
 * out into the scheduler loop.
 */
class Coroutine
{
    public bool $finished = false;

    public mixed $result = null;

    public ?Throwable $error = null;

    /** Coroutines blocked in Co::await() on this one; woken when it finishes. @var Coroutine[] */
    public array $joiners = [];

    /** Callbacks registered with Co::defer(), run LIFO when this coroutine ends. @var callable[] */
    public array $deferred = [];

    /** Per-coroutine state (see Context). GC'd with the coroutine, so it self-isolates. */
    public \ArrayObject $context;

    /** Whatever the fiber last handed to Fiber::suspend() — the scheduler instruction. */
    public mixed $lastYield = null;

    private Fiber $fiber;

    public function __construct(public readonly int $id, callable $callable)
    {
        $this->context = new \ArrayObject();
        $this->fiber = new Fiber(function () use ($callable) {
            try {
                $this->result = $callable();
            } catch (Throwable $e) {
                $this->error = $e;
            }
        });
    }

    /** Start or resume the fiber; capture the suspension instruction it yields. */
    public function tick(mixed $resumeValue = null): void
    {
        $this->lastYield = $this->fiber->isStarted()
            ? $this->fiber->resume($resumeValue)
            : $this->fiber->start();

        if ($this->fiber->isTerminated()) {
            $this->finished = true;
        }
    }
}
