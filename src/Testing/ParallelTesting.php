<?php

namespace Nitro\Testing;

use Closure;

/**
 * Hooks for a test suite split across several processes.
 *
 *     ParallelTesting::setUpProcess(fn () => Artisan::call('migrate:fresh'));
 *     ParallelTesting::setUpTestDatabase(fn ($db) => Seeder::run());
 *
 * Each process needs its own database so two tests never write to the same
 * rows; token() tells a process which one it is, so names can be derived
 * from it rather than collided over.
 */
class ParallelTesting
{
    /** @var array<string, array<int, Closure>> Callbacks, by hook name. */
    protected array $callbacks = [
        'setUpProcess' => [],
        'setUpTestCase' => [],
        'setUpTestDatabase' => [],
        'tearDownTestCase' => [],
        'tearDownProcess' => [],
    ];

    /** Run once when a test process starts. */
    public function setUpProcess(Closure $callback): static
    {
        return $this->on('setUpProcess', $callback);
    }

    /** Run before each test case. */
    public function setUpTestCase(Closure $callback): static
    {
        return $this->on('setUpTestCase', $callback);
    }

    /** Run once after this process's database has been created. */
    public function setUpTestDatabase(Closure $callback): static
    {
        return $this->on('setUpTestDatabase', $callback);
    }

    /** Run after each test case. */
    public function tearDownTestCase(Closure $callback): static
    {
        return $this->on('tearDownTestCase', $callback);
    }

    /** Run once as a test process exits. */
    public function tearDownProcess(Closure $callback): static
    {
        return $this->on('tearDownProcess', $callback);
    }

    /** Fire a hook, passing the arguments to every callback on it. */
    public function callHook(string $hook, mixed ...$arguments): void
    {
        foreach ($this->callbacks[$hook] ?? [] as $callback) {
            $callback(...$arguments);
        }
    }

    /**
     * Which process this is, or false when the suite is not split.
     *
     * Set by the runner in the environment, so a database name can be derived
     * from it without the processes agreeing on anything else.
     */
    public function token(): string|false
    {
        return getenv('TEST_TOKEN');
    }

    /** Whether this suite is running across several processes. */
    public function inParallel(): bool
    {
        return $this->token() !== false;
    }

    /** Give a resource a name no other process will use. */
    public function tokenise(string $name): string
    {
        return $this->inParallel() ? $name . '_test_' . $this->token() : $name;
    }

    /** Forget every registered callback. */
    public function flush(): static
    {
        foreach (array_keys($this->callbacks) as $hook) {
            $this->callbacks[$hook] = [];
        }

        return $this;
    }

    private function on(string $hook, Closure $callback): static
    {
        $this->callbacks[$hook][] = $callback;

        return $this;
    }
}
