<?php

namespace Nitro\Cache;

use Nitro\Cache\Drivers\ArrayStore;
use PHPUnit\Framework\Assert;

/**
 * A cache that works normally and remembers what was asked of it.
 *
 *     Cache::fake();
 *
 *     $this->get('/reports/monthly');
 *
 *     Cache::assertCached('report.monthly');
 *
 * Deliberately not only a recorder: code that caches usually reads back what it
 * wrote, so a cache that stored nothing would fail tests for the wrong reason.
 * Values go into an array store and the calls are noted alongside.
 *
 * Laravel has no Cache::fake() — it expects the array driver to be configured
 * instead. That works, but it means a test proving something is cached has to
 * reach into the store and check, which reads as the test caching rather than
 * the code doing it. Asserting the intent is clearer, so this exists.
 */
class CacheFake extends Repository
{
    /** @var array<int, array{operation: string, key: string, ttl: int|null}> */
    protected array $operations = [];

    public function __construct()
    {
        parent::__construct(new ArrayStore());
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->note('put', $key, $ttl);

        return parent::put($key, $value, $ttl);
    }

    public function forever(string $key, mixed $value): bool
    {
        $this->note('forever', $key, null);

        return parent::forever($key, $value);
    }

    public function forget(string $key): bool
    {
        $this->note('forget', $key, null);

        return parent::forget($key);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->note('get', $key, null);

        return parent::get($key, $default);
    }

    protected function note(string $operation, string $key, ?int $ttl): void
    {
        $this->operations[] = ['operation' => $operation, 'key' => $key, 'ttl' => $ttl];
    }

    /**
     * Every call, in order.
     *
     * @return array<int, array{operation: string, key: string, ttl: int|null}>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    // ─── Assertions ─────────────────────────────────────────

    /** @param int|null $ttl Assert the lifetime too, when it matters. */
    public function assertCached(string $key, ?int $ttl = null): static
    {
        foreach ($this->operations as $operation) {
            if ($operation['key'] !== $key || ! in_array($operation['operation'], ['put', 'forever'], true)) {
                continue;
            }

            if ($ttl !== null) {
                Assert::assertSame($ttl, $operation['ttl'], "The key [{$key}] was cached for a different time.");
            }

            Assert::assertTrue(true);

            return $this;
        }

        Assert::fail("Nothing was cached under [{$key}].");
    }

    public function assertNotCached(string $key): static
    {
        foreach ($this->operations as $operation) {
            if ($operation['key'] === $key && in_array($operation['operation'], ['put', 'forever'], true)) {
                Assert::fail("Something was unexpectedly cached under [{$key}].");
            }
        }

        Assert::assertTrue(true);

        return $this;
    }

    public function assertForgotten(string $key): static
    {
        foreach ($this->operations as $operation) {
            if ($operation['key'] === $key && $operation['operation'] === 'forget') {
                Assert::assertTrue(true);

                return $this;
            }
        }

        Assert::fail("The key [{$key}] was never forgotten.");
    }

    public function assertNothingCached(): static
    {
        $written = array_values(array_filter(
            $this->operations,
            static fn (array $o): bool => in_array($o['operation'], ['put', 'forever'], true),
        ));

        Assert::assertEmpty(
            $written,
            'Keys were cached unexpectedly: '
                . implode(', ', array_unique(array_column($written, 'key'))) . '.'
        );

        return $this;
    }
}
