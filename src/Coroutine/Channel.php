<?php

namespace Nitro\Coroutine;

use RuntimeException;

/**
 * A coroutine-safe queue for passing values between coroutines — the way they
 * coordinate without shared-memory races. push()/pop() SUSPEND the calling
 * coroutine (not the process) when the channel is full/empty, and the scheduler
 * runs other coroutines meanwhile.
 *
 * Pure PHP over the Scheduler's park/schedule — no Swoole channel. Capacity 0 is an
 * unbuffered (rendezvous) channel: a push hands directly to a waiting pop.
 */
class Channel
{
    /** @var array<int, mixed> */
    private array $buffer = [];

    /** Coroutines blocked in push(), each with the value they're trying to send. @var array<int, array{co: Coroutine, value: mixed}> */
    private array $pushWaiters = [];

    /** Coroutines blocked in pop(). @var Coroutine[] */
    private array $popWaiters = [];

    public function __construct(private readonly int $capacity = 0)
    {
    }

    public function push(mixed $value): void
    {
        $scheduler = $this->scheduler();

        // A waiting receiver takes the value directly — no buffering needed.
        if ($this->popWaiters !== []) {
            $scheduler->schedule(array_shift($this->popWaiters), $value);

            return;
        }

        if (count($this->buffer) < $this->capacity) {
            $this->buffer[] = $value;

            return;
        }

        // Full (or unbuffered with no receiver) — park the sender until a pop frees room.
        $this->pushWaiters[] = ['co' => $scheduler->currentCoroutine(), 'value' => $value];
        $scheduler->park();
    }

    public function pop(): mixed
    {
        $scheduler = $this->scheduler();

        if ($this->buffer !== []) {
            $value = array_shift($this->buffer);

            // A parked sender's value now fits — admit it and wake the sender.
            if ($this->pushWaiters !== []) {
                $waiter = array_shift($this->pushWaiters);
                $this->buffer[] = $waiter['value'];
                $scheduler->schedule($waiter['co']);
            }

            return $value;
        }

        // Empty buffer but a sender is parked (unbuffered handoff) — take its value.
        if ($this->pushWaiters !== []) {
            $waiter = array_shift($this->pushWaiters);
            $scheduler->schedule($waiter['co']);

            return $waiter['value'];
        }

        // Nothing available — park the receiver; a future push wakes it with the value.
        $this->popWaiters[] = $scheduler->currentCoroutine();

        return $scheduler->park();
    }

    public function length(): int
    {
        return count($this->buffer);
    }

    public function isEmpty(): bool
    {
        return $this->buffer === [];
    }

    private function scheduler(): Scheduler
    {
        return Scheduler::current()
            ?? throw new RuntimeException('Channels can only be used inside a coroutine (Co::run()/Co::parallel()).');
    }
}
