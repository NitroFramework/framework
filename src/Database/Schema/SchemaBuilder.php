<?php

namespace Nitro\Database\Schema;

use Closure;
use Nitro\Database\DB;

/**
 * Runs DDL (create/alter/drop) via Blueprints and the schema grammar.
 */
class SchemaBuilder
{
    // ─── DDL Operations ───────────────────────────────────

    public static function create(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        DB::statement($blueprint->toCreateSql());
    }

    public static function table(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        foreach ($blueprint->toAlterSql() as $sql) {
            DB::statement($sql);
        }
    }

    public static function drop(string $table): void
    {
        DB::statement("DROP TABLE {$table}");
    }

    public static function dropIfExists(string $table): void
    {
        DB::statement("DROP TABLE IF EXISTS {$table}");
    }

    public static function rename(string $from, string $to): void
    {
        DB::statement("RENAME TABLE {$from} TO {$to}");
    }

    // ─── Existence Checks ─────────────────────────────────

    public static function hasTable(string $table): bool
    {
        $cached = SchemaCache::hasTable($table);
        if ($cached !== null) return $cached;

        $grammar = DB::grammar();
        $result = DB::selectOne($grammar->compileHasTable(), [$table]);
        return $result && $result->count > 0;
    }

    public static function hasColumn(string $table, string $column): bool
    {
        $cached = SchemaCache::hasColumn($table, $column);
        if ($cached !== null) return $cached;

        $grammar = DB::grammar();
        $result = DB::selectOne($grammar->compileHasColumn(), [$table, $column]);
        return $result && $result->count > 0;
    }

    // ─── Introspection ────────────────────────────────────

    public static function getTables(): array
    {
        $cached = SchemaCache::tables();
        if ($cached !== null) return $cached;

        $grammar = DB::grammar();
        return DB::select($grammar->compileTables());
    }

    public static function getViews(): array
    {
        $grammar = DB::grammar();
        return DB::select($grammar->compileViews());
    }

    public static function getColumnListing(string $table): array
    {
        $cached = SchemaCache::columnListing($table);
        if ($cached !== null) return $cached;

        $grammar = DB::grammar();
        $results = DB::select($grammar->compileColumnListing(), [$table]);
        // MySQL 8 returns information_schema columns uppercase by default;
        // older versions and other engines return them lowercase. Accept
        // either rather than picking a side.
        return array_map(static function ($r): string {
            $row = (array) $r;
            return (string) ($row['column_name'] ?? $row['COLUMN_NAME'] ?? '');
        }, $results);
    }

    public static function getColumns(string $table): array
    {
        $cached = SchemaCache::columns($table);
        if ($cached !== null) return $cached;

        $grammar = DB::grammar();
        return DB::select($grammar->compileSchemaColumns(), [$table]);
    }

    public static function getIndexes(string $table): array
    {
        $cached = SchemaCache::indexes($table);
        if ($cached !== null) return $cached;

        $grammar = DB::grammar();
        return DB::select($grammar->compileIndexes(), [$table]);
    }

    public static function getForeignKeys(string $table): array
    {
        $cached = SchemaCache::foreignKeys($table);
        if ($cached !== null) return $cached;

        $grammar = DB::grammar();
        return DB::select($grammar->compileForeignKeys(), [$table]);
    }
}
