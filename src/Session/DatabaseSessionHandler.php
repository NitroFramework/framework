<?php

namespace Nitro\Session;

use Nitro\Database\DB;
use SessionHandlerInterface;
use Throwable;

/**
 * SQL-backed session handler.
 *
 * One row per session in a `sessions` table (`id`, `payload`, `last_activity`),
 * so sessions outlive the container's filesystem and every replica reads the
 * same state. The payload is base64-encoded on the way in because a serialized
 * session is binary and the column is text.
 *
 * Expected schema:
 *   id            string, primary key
 *   payload       text
 *   last_activity integer, indexed
 */
class DatabaseSessionHandler implements SessionHandlerInterface, ExistenceAwareInterface
{
    /** Whether the session is known to be persisted already; null when unknown. */
    private ?bool $exists = null;

    public function __construct(
        private string $table = 'sessions',
        private int $minutes = 120,
    ) {}

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $row = DB::table($this->table)->where('id', $id)->first();

        if ($row === null) {
            return '';
        }

        if ((int) ($row->last_activity ?? 0) + ($this->minutes * 60) < time()) {
            return '';
        }

        $payload = base64_decode((string) ($row->payload ?? ''), true);

        return $payload === false ? '' : $payload;
    }

    /**
     * Write the payload, inserting when the id is new.
     *
     * The update runs first and the insert only when it touched nothing, so the
     * common path is a single statement. A concurrent insert of the same id
     * loses the race on the primary key; that is caught and treated as written,
     * since the winner stored an equivalent payload.
     */
    /**
     * Record whether the session is already persisted.
     *
     * Lets a write go straight to the statement it needs instead of trying an
     * update and falling back.
     */
    public function setExists(bool $value): SessionHandlerInterface
    {
        $this->exists = $value;

        return $this;
    }

    public function write(string $id, string $data): bool
    {
        $values = [
            'payload'       => base64_encode($data),
            'last_activity' => time(),
        ];

        if ($this->exists !== false && DB::table($this->table)->where('id', $id)->update($values) > 0) {
            return true;
        }

        try {
            DB::table($this->table)->insert($values + ['id' => $id]);
        } catch (Throwable) {
            return true;
        }

        return true;
    }

    public function destroy(string $id): bool
    {
        DB::table($this->table)->where('id', $id)->delete();

        return true;
    }

    /**
     * Delete rows idle past the lifetime, up to $limit of them.
     *
     * A bounded sweep collects the ids first and deletes by key, because a
     * LIMIT on a DELETE is not portable across the supported grammars.
     *
     * @param int $limit Rows to remove at most; 0 for no limit.
     */
    public function gc(int $max_lifetime, int $limit = 0): int|false
    {
        $cutoff = time() - $max_lifetime;

        if ($limit <= 0) {
            return DB::table($this->table)->where('last_activity', '<', $cutoff)->delete();
        }

        $ids = DB::table($this->table)
            ->where('last_activity', '<', $cutoff)
            ->limit($limit)
            ->pluck('id');

        if ($ids === []) {
            return 0;
        }

        return DB::table($this->table)->whereIn('id', $ids)->delete();
    }
}
