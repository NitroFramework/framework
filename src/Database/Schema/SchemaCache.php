<?php

namespace Nitro\Database\Schema;

/**
 * Disk-backed cache of database introspection results. Populated by
 * `php nitro optimize`; consulted at runtime by SchemaBuilder so calls
 * like getColumnListing() / getColumns() / hasTable() serve from a flat
 * PHP file instead of issuing information_schema round-trips.
 *
 * Lazily loaded on first lookup so apps that never introspect at runtime
 * pay nothing. Memoized per process — single file read, then memory.
 *
 * Honest scope:
 *   The framework's ORM/query-builder does not probe the schema during
 *   normal request handling, so the cache has no automatic effect on
 *   the hot path. Where it DOES help: app code that calls SchemaBuilder
 *   methods (admin panels, dynamic forms, model generators, validators
 *   that consult column types) gets every probe served from the file.
 */
class SchemaCache
{
    private const FILENAME = 'schema.php';

    /** Process-lifetime memoization of the loaded cache. */
    private static ?array $cache = null;

    /** Set true once we've tried to load (so we don't re-read on miss). */
    private static bool $loadAttempted = false;

    /**
     * Disable cache lookup for a request — used during migrations and
     * other DDL paths that need fresh schema reads.
     */
    private static bool $bypass = false;

    public static function bypass(bool $bypass = true): void
    {
        self::$bypass = $bypass;
    }

    public static function tables(): ?array
    {
        return self::load()['tables'] ?? null;
    }

    /** @return array<int, object>|null  list of column metadata objects, or null on cache miss */
    public static function columns(string $table): ?array
    {
        $entries = self::load()['table_columns'][$table] ?? null;
        return $entries === null ? null : array_map(
            static fn(array $row) => (object) $row,
            $entries,
        );
    }

    /** @return string[]|null */
    public static function columnListing(string $table): ?array
    {
        return self::load()['column_listing'][$table] ?? null;
    }

    /** @return array<int, object>|null */
    public static function indexes(string $table): ?array
    {
        $entries = self::load()['indexes'][$table] ?? null;
        return $entries === null ? null : array_map(
            static fn(array $row) => (object) $row,
            $entries,
        );
    }

    /** @return array<int, object>|null */
    public static function foreignKeys(string $table): ?array
    {
        $entries = self::load()['foreign_keys'][$table] ?? null;
        return $entries === null ? null : array_map(
            static fn(array $row) => (object) $row,
            $entries,
        );
    }

    public static function hasTable(string $table): ?bool
    {
        $tables = self::load()['table_names'] ?? null;
        return $tables === null ? null : in_array($table, $tables, true);
    }

    public static function hasColumn(string $table, string $column): ?bool
    {
        $listing = self::columnListing($table);
        return $listing === null ? null : in_array($column, $listing, true);
    }

    /**
     * Drop the cached file. Called from the migrator after any
     * up()/down() runs so the next process boots with fresh data.
     */
    public static function clear(): void
    {
        self::$cache = null;
        self::$loadAttempted = false;
        $path = self::cacheFilePath();
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    /** Reset the in-process memoization. Tests only. */
    public static function flushMemo(): void
    {
        self::$cache = null;
        self::$loadAttempted = false;
    }

    private static function load(): array
    {
        if (self::$bypass) {
            return [];
        }
        if (self::$loadAttempted) {
            return self::$cache ?? [];
        }

        self::$loadAttempted = true;
        $path = self::cacheFilePath();
        if ($path === null || !is_file($path)) {
            return [];
        }

        $data = require $path;
        return self::$cache = is_array($data) ? $data : [];
    }

    private static function cacheFilePath(): ?string
    {
        try {
            return app('paths')->cache(self::FILENAME);
        } catch (\Throwable) {
            return null;
        }
    }
}
