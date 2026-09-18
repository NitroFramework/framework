<?php

namespace Tests\Unit\Queue;

/**
 * An in-memory stand-in for a phpredis connection, covering the commands
 * {@see \Nitro\Queue\Drivers\RedisQueue} issues.
 *
 * {@see eval()} emulates the driver's one Lua script rather than interpreting
 * Lua. That means this double proves the driver moves jobs between its keys
 * correctly, but says nothing about whether those moves are atomic — only a
 * real server can answer that, which is what RedisQueueTest is for.
 */
class FakeRedis
{
    /** @var array<string, int> */
    public array $counters = [];

    /** @var array<string, array<int, string>> */
    public array $lists = [];

    /** @var array<string, array<string, float>> member => score */
    public array $sets = [];

    /** @var array<string, array<string, string>> */
    public array $hashes = [];

    public function incr(string $key): int
    {
        return $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;
    }

    public function hSet(string $key, string $field, string $value): int
    {
        $new = ! isset($this->hashes[$key][$field]);

        $this->hashes[$key][$field] = $value;

        return $new ? 1 : 0;
    }

    public function hGet(string $key, string $field): string|false
    {
        return $this->hashes[$key][$field] ?? false;
    }

    public function hDel(string $key, string $field): int
    {
        $existed = isset($this->hashes[$key][$field]);

        unset($this->hashes[$key][$field]);

        return $existed ? 1 : 0;
    }

    public function rPush(string $key, string $value): int
    {
        $this->lists[$key][] = $value;

        return count($this->lists[$key]);
    }

    public function lPop(string $key): string|false
    {
        if (empty($this->lists[$key])) {
            return false;
        }

        return array_shift($this->lists[$key]);
    }

    public function lRem(string $key, string $value, int $count): int
    {
        $before = count($this->lists[$key] ?? []);

        $this->lists[$key] = array_values(array_filter(
            $this->lists[$key] ?? [],
            static fn (string $member): bool => $member !== $value,
        ));

        return $before - count($this->lists[$key]);
    }

    public function lLen(string $key): int
    {
        return count($this->lists[$key] ?? []);
    }

    public function zAdd(string $key, float $score, string $member): int
    {
        $new = ! isset($this->sets[$key][$member]);

        $this->sets[$key][$member] = $score;

        return $new ? 1 : 0;
    }

    public function zRem(string $key, string $member): int
    {
        $existed = isset($this->sets[$key][$member]);

        unset($this->sets[$key][$member]);

        return $existed ? 1 : 0;
    }

    public function zCard(string $key): int
    {
        return count($this->sets[$key] ?? []);
    }

    /**
     * Emulate the driver's migration script: move every member scored at or
     * below the cutoff from the sorted set into the list.
     *
     * @param array<int, string> $arguments [sorted set, list, cutoff]
     */
    public function eval(string $script, array $arguments = [], int $numKeys = 0): int
    {
        [$from, $to, $cutoff] = $arguments;

        $due = array_keys(array_filter(
            $this->sets[$from] ?? [],
            static fn (float $score): bool => $score <= (float) $cutoff,
        ));

        asort($due);

        foreach ($due as $member) {
            unset($this->sets[$from][$member]);

            $this->lists[$to][] = $member;
        }

        return count($due);
    }
}
