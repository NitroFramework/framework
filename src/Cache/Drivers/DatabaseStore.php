<?php

namespace Nitro\Cache\Drivers;

use Nitro\Cache\Contracts\StoreInterface;
use Nitro\Database\DB;
use Throwable;

/**
 * SQL-backed cache store.
 *
 * One row per entry in a `cache` table (`key`, `value`, `expiration`), so the
 * cache outlives a container's filesystem and every replica reads the same
 * state without a separate service to run.
 *
 * Expired rows are removed when they are next read, and a write to an expired
 * key overwrites it, so nothing depends on a sweep having run.
 *
 * Expected schema:
 *   key        string, primary key
 *   value      text
 *   expiration integer, indexed
 */
class DatabaseStore implements StoreInterface
{
    /**
     * @param string $table  Table holding the entries.
     * @param string $prefix Prepended to every key.
     */
    public function __construct(
        protected string $table = 'cache',
        protected string $prefix = '',
    ) {}

    public function get(string $key): mixed
    {
        $row = DB::table($this->table)
            ->where('key', $this->prefix . $key)
            ->first();

        if ($row === null) {
            return null;
        }

        $row = (array) $row;

        if ((int) $row['expiration'] !== 0 && (int) $row['expiration'] <= time()) {
            $this->forget($key);

            return null;
        }

        return $this->unserialize($row['value']);
    }

    /**
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        $found = [];

        foreach ($keys as $key) {
            $found[$key] = $this->get($key);
        }

        return $found;
    }

    public function put(string $key, mixed $value, int $seconds): bool
    {
        $values = [
            'value'      => $this->serialize($value),
            'expiration' => $seconds > 0 ? time() + $seconds : 0,
        ];

        $prefixed = $this->prefix . $key;

        if (DB::table($this->table)->where('key', $prefixed)->update($values) > 0) {
            return true;
        }

        try {
            DB::table($this->table)->insert($values + ['key' => $prefixed]);
        } catch (Throwable) {
            // Another process inserted the same key between the update and
            // here; its value is as valid as this one.
            return true;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function putMany(array $values, int $seconds): bool
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    /**
     * Store a value only when the key is absent or has expired.
     *
     * The insert is what makes this atomic: the primary key rejects a second
     * caller, so exactly one of them sees a successful write.
     */
    public function add(string $key, mixed $value, int $seconds): bool
    {
        $prefixed = $this->prefix . $key;
        $expiration = $seconds > 0 ? time() + $seconds : 0;

        try {
            DB::table($this->table)->insert([
                'key'        => $prefixed,
                'value'      => $this->serialize($value),
                'expiration' => $expiration,
            ]);

            return true;
        } catch (Throwable) {
            // The key exists. It may still be claimable if it has expired.
        }

        $updated = DB::table($this->table)
            ->where('key', $prefixed)
            ->where('expiration', '!=', 0)
            ->where('expiration', '<=', time())
            ->update([
                'value'      => $this->serialize($value),
                'expiration' => $expiration,
            ]);

        return $updated > 0;
    }

    public function increment(string $key, int $value = 1): int|bool
    {
        $current = $this->get($key);

        if ($current !== null && ! is_numeric($current)) {
            return false;
        }

        $new = (int) $current + $value;

        $this->put($key, $new, $this->remainingLifetime($key));

        return $new;
    }

    public function decrement(string $key, int $value = 1): int|bool
    {
        return $this->increment($key, $value * -1);
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->put($key, $value, 0);
    }

    public function forget(string $key): bool
    {
        return DB::table($this->table)
            ->where('key', $this->prefix . $key)
            ->delete() > 0;
    }

    public function flush(): bool
    {
        DB::table($this->table)->delete();

        return true;
    }

    /**
     * Remove every entry that has expired.
     *
     * Nothing depends on this having run — a read drops an expired entry on
     * sight — but it keeps the table from growing without bound.
     */
    public function collectGarbage(): int
    {
        return DB::table($this->table)
            ->where('expiration', '!=', 0)
            ->where('expiration', '<=', time())
            ->delete();
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * How long an existing key has left, so an increment does not extend it.
     */
    protected function remainingLifetime(string $key): int
    {
        $row = DB::table($this->table)
            ->where('key', $this->prefix . $key)
            ->first();

        if ($row === null) {
            return 0;
        }

        $expiration = (int) ((array) $row)['expiration'];

        return $expiration === 0 ? 0 : max(1, $expiration - time());
    }

    protected function serialize(mixed $value): string
    {
        return base64_encode(serialize($value));
    }

    protected function unserialize(string $value): mixed
    {
        $decoded = base64_decode($value, true);

        return $decoded === false ? null : @unserialize($decoded);
    }
}
