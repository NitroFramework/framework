<?php

namespace Nitro\Session;

use Closure;
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
 * Each row also records who the session belongs to and where it came
 * from, so an application can show a user their active sessions and end
 * one.
 *
 * Expected schema:
 *   id            string, primary key
 *   user_id       integer, nullable, indexed
 *   ip_address    string(45), nullable
 *   user_agent    text, nullable
 *   payload       text
 *   last_activity integer, indexed
 */
class DatabaseSessionHandler implements SessionHandlerInterface, ExistenceAwareInterface
{
    /** Whether the session is known to be persisted already; null when unknown. */
    private ?bool $exists = null;

    /**
     * @param Closure(): (int|string|null) $userId  Who is signed in, or null.
     * @param Closure(): array{ip_address?: ?string, user_agent?: ?string} $requestContext
     *   Where the request came from.
     *
     * Closures, so the session layer does not require the auth or http
     * layers; absent, those columns are not written.
     */
    public function __construct(
        private string $table = 'sessions',
        private int $minutes = 120,
        private ?Closure $userId = null,
        private ?Closure $requestContext = null,
    ) {}

    /**
     * The columns every write sets.
     *
     * In one place so an insert and an update store the same thing.
     *
     * @return array<string, mixed>
     */
    protected function defaultPayload(string $data): array
    {
        $payload = [
            'payload'       => base64_encode($data),
            'last_activity' => time(),
        ];

        $this->addUserInformation($payload);
        $this->addRequestInformation($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    protected function addUserInformation(array &$payload): void
    {
        if ($this->userId === null) {
            return;
        }

        $payload['user_id'] = ($this->userId)();
    }

    /** @param array<string, mixed> $payload */
    protected function addRequestInformation(array &$payload): void
    {
        if ($this->requestContext === null) {
            return;
        }

        $context = ($this->requestContext)();

        $payload['ip_address'] = $context['ip_address'] ?? null;
        $payload['user_agent'] = $context['user_agent'] ?? null;
    }

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
    /**
     * Record whether the session is already persisted.
     *
     * Lets a write go straight to the statement it needs.
     */
    public function setExists(bool $value): SessionHandlerInterface
    {
        $this->exists = $value;

        return $this;
    }

    public function write(string $id, string $data): bool
    {
        $values = $this->defaultPayload($data);

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
     * Collects ids first and deletes by key, since LIMIT on a DELETE is
     * not portable.
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
