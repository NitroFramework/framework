<?php

namespace Nitro\Database\Query\Grammar;

/**
 * SQLite query grammar. Query compilation (select/insert/update/delete) is
 * standard enough that the base Grammar handles it; what differs is schema
 * introspection — SQLite has no information_schema, so these read sqlite_master
 * and the pragma_* table-valued functions instead. Column aliases mirror the
 * base (MySQL) grammar so callers see the same shape.
 */
class SqliteGrammar extends Grammar
{
    public function compileTables(): string
    {
        return "SELECT name AS table_name
                FROM sqlite_master
                WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
                ORDER BY name";
    }

    public function compileViews(): string
    {
        return "SELECT name AS table_name, sql AS view_definition
                FROM sqlite_master
                WHERE type = 'view'
                ORDER BY name";
    }

    public function compileColumnListing(): string
    {
        return "SELECT name AS column_name FROM pragma_table_info(?)";
    }

    public function compileSchemaColumns(): string
    {
        return "SELECT name AS column_name,
                       type AS data_type,
                       type AS column_type,
                       CASE WHEN \"notnull\" = 0 THEN 'YES' ELSE 'NO' END AS is_nullable,
                       dflt_value AS column_default
                FROM pragma_table_info(?)";
    }

    public function compileIndexes(): string
    {
        return "SELECT name AS index_name
                FROM sqlite_master
                WHERE type = 'index' AND tbl_name = ?
                ORDER BY name";
    }

    public function compileForeignKeys(): string
    {
        return "SELECT \"from\" AS column_name,
                       \"table\" AS referenced_table_name,
                       \"to\" AS referenced_column_name
                FROM pragma_foreign_key_list(?)";
    }

    public function compileHasTable(): string
    {
        return "SELECT COUNT(*) AS count
                FROM sqlite_master
                WHERE type = 'table' AND name = ?";
    }

    public function compileHasColumn(): string
    {
        return "SELECT COUNT(*) AS count FROM pragma_table_info(?) WHERE name = ?";
    }

    /**
     * SQLite has no row-level lock clause, and needs none: a write transaction
     * takes a lock over the whole database, so only one writer is ever inside
     * one at a time. The transaction the caller is already holding IS the lock
     * that FOR UPDATE would ask MySQL for.
     *
     * Returning an empty string rather than throwing is what lets the same
     * lockForUpdate() code run on SQLite locally and MySQL in production, which
     * is the arrangement most applications actually develop under.
     */
    public function compileLock(string $type): string
    {
        return '';
    }

    /**
     * SQLite has no DATE() over a DATETIME string in the MySQL sense; date()
     * is the equivalent, and it also copes with the ISO strings this framework
     * writes.
     */
    public function compileDate(string $wrappedColumn): string
    {
        return "date({$wrappedColumn})";
    }
}
