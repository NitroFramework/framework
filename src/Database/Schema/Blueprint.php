<?php

namespace Nitro\Database\Schema;

use Nitro\Database\Query\RawExpression;

/**
 * Table definition DSL — describes columns, indexes and keys for schema create/alter.
 */
class Blueprint
{
    protected string $table;
    protected array $columns = [];
    protected array $commands = [];
    protected array $indexes = [];

    // Current column being modified
    private ?array $currentColumn = null;

    /**
     * The name of the most-recent foreignId() column, used by constrained()
     * to know which column to attach the FK to. Reset is implicit — each
     * foreignId() call overwrites it.
     */
    private ?string $lastForeignIdColumn = null;

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    // ─── Column Types ─────────────────────────────────────

    public function id(string $column = 'id'): static
    {
        return $this->addColumn($column, 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY');
    }

    public function string(string $column, int $length = 255): static
    {
        return $this->addColumn($column, "VARCHAR({$length})");
    }

    public function text(string $column): static
    {
        return $this->addColumn($column, 'TEXT');
    }

    public function longText(string $column): static
    {
        return $this->addColumn($column, 'LONGTEXT');
    }

    public function integer(string $column): static
    {
        return $this->addColumn($column, 'INT');
    }

    public function mediumText(string $column): static
    {
        return $this->addColumn($column, 'MEDIUMTEXT');
    }

    public function mediumInteger(string $column): static
    {
        return $this->addColumn($column, 'MEDIUMINT');
    }

    public function char(string $column, int $length = 255): static
    {
        return $this->addColumn($column, "CHAR({$length})");
    }

    public function binary(string $column): static
    {
        return $this->addColumn($column, 'BLOB');
    }

    public function year(string $column): static
    {
        return $this->addColumn($column, 'YEAR');
    }

    public function time(string $column): static
    {
        return $this->addColumn($column, 'TIME');
    }

    public function tinyInteger(string $column): static
    {
        return $this->addColumn($column, 'TINYINT');
    }

    public function smallInteger(string $column): static
    {
        return $this->addColumn($column, 'SMALLINT');
    }

    public function bigInteger(string $column): static
    {
        return $this->addColumn($column, 'BIGINT');
    }

    public function unsignedBigInteger(string $column): static
    {
        return $this->addColumn($column, 'BIGINT UNSIGNED');
    }

    public function float(string $column, int $precision = 8, int $scale = 2): static
    {
        return $this->addColumn($column, "FLOAT({$precision},{$scale})");
    }

    public function decimal(string $column, int $precision = 8, int $scale = 2): static
    {
        return $this->addColumn($column, "DECIMAL({$precision},{$scale})");
    }

    public function boolean(string $column): static
    {
        return $this->addColumn($column, 'TINYINT(1)');
    }

    public function date(string $column): static
    {
        return $this->addColumn($column, 'DATE');
    }

    public function datetime(string $column): static
    {
        return $this->addColumn($column, 'DATETIME');
    }

    public function timestamp(string $column): static
    {
        return $this->addColumn($column, 'TIMESTAMP');
    }

    public function timestamps(): static
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
        return $this;
    }

    public function softDeletes(string $column = 'deleted_at'): static
    {
        return $this->timestamp($column)->nullable();
    }

    public function json(string $column): static
    {
        return $this->addColumn($column, 'JSON');
    }

    public function enum(string $column, array $values): static
    {
        // Quote each enum value safely — single-quote escape per MySQL.
        $quoted = implode(', ', array_map(
            static fn($v) => "'" . str_replace("'", "''", (string) $v) . "'",
            $values
        ));
        return $this->addColumn($column, "ENUM({$quoted})");
    }

    // ─── Column Modifiers ─────────────────────────────────

    public function nullable(): static
    {
        if ($this->currentColumn) {
            $this->currentColumn['nullable'] = true;
            $this->updateCurrentColumn();
        }
        return $this;
    }

    public function default(mixed $value): static
    {
        if ($this->currentColumn) {
            $this->currentColumn['default'] = $value;
            $this->currentColumn['has_default'] = true;
            $this->updateCurrentColumn();
        }
        return $this;
    }

    public function useCurrent(): static
    {
        return $this->default(new RawExpression('CURRENT_TIMESTAMP'));
    }

    public function unsigned(): static
    {
        if ($this->currentColumn) {
            $this->currentColumn['unsigned'] = true;
            $this->updateCurrentColumn();
        }
        return $this;
    }

    /**
     * Mark a column (or list of columns) UNIQUE.
     *
     * Three call shapes — matches Laravel:
     *   $table->string('slug')->unique();          chained modifier (inline UNIQUE)
     *   $table->unique('slug');                    standalone unique INDEX on a single col
     *   $table->unique(['user_id', 'role']);       composite UNIQUE INDEX
     *
     * The chained-modifier form keeps the existing inline-UNIQUE rendering
     * (column DEFINITION includes UNIQUE). The standalone/composite form
     * appends a separate UNIQUE INDEX clause via the indexes pipeline so
     * naming is consistent (uniq_{table}_{col1}_{col2}…).
     */
    public function unique(string|array|null $columns = null, ?string $name = null): static
    {
        // Chained-modifier form — no explicit columns; flag the current column.
        if ($columns === null) {
            if ($this->currentColumn) {
                $this->currentColumn['unique'] = true;
                $this->updateCurrentColumn();
            }
            return $this;
        }

        $cols = is_array($columns) ? array_values($columns) : [$columns];
        $this->indexes[] = [
            'cols'   => $cols,
            'name'   => $name ?? $this->defaultIndexName('uniq', $cols),
            'unique' => true,
        ];
        return $this;
    }

    /**
     * Add an INDEX.
     *
     *   $table->string('email')->index();         chained modifier
     *   $table->index('slug');                    standalone
     *   $table->index(['status', 'created_at']);  composite
     *   $table->index('slug', 'idx_my_name');     explicit index name
     */
    public function index(string|array|null $columns = null, ?string $name = null): static
    {
        // Chained-modifier form — index the just-added column.
        if ($columns === null) {
            if ($this->currentColumn) {
                $this->indexes[] = [
                    'cols'   => [$this->currentColumn['name']],
                    'name'   => $name ?? $this->defaultIndexName('idx', [$this->currentColumn['name']]),
                    'unique' => false,
                ];
            }
            return $this;
        }

        $cols = is_array($columns) ? array_values($columns) : [$columns];
        $this->indexes[] = [
            'cols'   => $cols,
            'name'   => $name ?? $this->defaultIndexName('idx', $cols),
            'unique' => false,
        ];
        return $this;
    }

    /** Build the conventional index name from prefix + table + col list. */
    private function defaultIndexName(string $prefix, array $columns): string
    {
        return $prefix . '_' . $this->table . '_' . implode('_', $columns);
    }

    public function after(string $column): static
    {
        if ($this->currentColumn) {
            $this->currentColumn['after'] = $column;
            $this->updateCurrentColumn();
        }
        return $this;
    }

    public function comment(string $comment): static
    {
        if ($this->currentColumn) {
            $this->currentColumn['comment'] = $comment;
            $this->updateCurrentColumn();
        }
        return $this;
    }

    // ─── Keys & Indexes ───────────────────────────────────

    public function primary(string|array $columns): static
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $quoted = array_map([$this, 'quote'], $columns);
        $this->commands[] = 'PRIMARY KEY (' . implode(', ', $quoted) . ')';
        return $this;
    }

    public function foreign(string $column): ForeignKeyBuilder
    {
        return new ForeignKeyBuilder($this, $column);
    }

    /**
     * Add an UNSIGNED BIGINT column intended to hold a foreign key, and
     * remember it so a following constrained() knows which column to
     * attach the FK to. Laravel-shaped:
     *
     *   $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
     *   $table->foreignId('category_id')->nullable()->constrained('categories')->onDelete('set null');
     *
     * Returns the Blueprint (NOT a foreign-key builder) so column modifiers
     * like nullable() / default() still chain correctly. The FK setup
     * starts when ->constrained() is called.
     */
    public function foreignId(string $column): static
    {
        $this->addColumn($column, 'BIGINT UNSIGNED');
        $this->lastForeignIdColumn = $column;
        return $this;
    }

    /**
     * Attach a foreign-key constraint to the most-recent foreignId() column.
     *
     *   constrained()                  — implicit table from column name
     *                                    ('user_id' → 'users', naive +s)
     *   constrained('users')           — explicit table, references id
     *   constrained('users', 'uuid')   — explicit table + referenced column
     */
    public function constrained(?string $table = null, string $referencesColumn = 'id'): ForeignKeyBuilder
    {
        if ($this->lastForeignIdColumn === null) {
            throw new \LogicException(
                'constrained() must follow a foreignId() call — there is no foreign-key column to attach to.'
            );
        }

        $column = $this->lastForeignIdColumn;
        $table  = $table ?? $this->guessForeignTableName($column);

        return (new ForeignKeyBuilder($this, $column))
            ->references($referencesColumn)
            ->on($table);
    }

    /**
     * Convention used by constrained() when the table isn't passed.
     * 'user_id' → 'users'. Naive — appends 's'. Pass the table name
     * explicitly when irregular ('category_id' → 'categories' not
     * 'categorys').
     */
    private function guessForeignTableName(string $column): string
    {
        $stem = preg_replace('/_id$/', '', $column);
        return $stem . 's';
    }

    /**
     * Foreign-key builders register themselves through this method so
     * Blueprint owns the command slot. Each foreign-key is its own slot;
     * subsequent calls from the SAME builder rewrite the same slot via
     * replaceCommand().
     */
    public function addForeignCommand(string $sql): int
    {
        $this->commands[] = $sql;
        return array_key_last($this->commands);
    }

    /** Rewrite a previously-appended command (used by ForeignKeyBuilder). */
    public function replaceCommand(int $index, string $sql): void
    {
        if (!array_key_exists($index, $this->commands)) {
            throw new \OutOfRangeException("No command at index {$index} to replace.");
        }
        $this->commands[$index] = $sql;
    }

    // ─── Drop Operations (for alter) ──────────────────────

    public function dropColumn(string|array $columns): static
    {
        $columns = is_array($columns) ? $columns : [$columns];
        foreach ($columns as $col) {
            $this->commands[] = "DROP COLUMN " . $this->quote($col);
        }
        return $this;
    }

    public function renameColumn(string $from, string $to): static
    {
        $this->commands[] = "RENAME COLUMN " . $this->quote($from) . " TO " . $this->quote($to);
        return $this;
    }

    public function dropForeign(string $index): static
    {
        $this->commands[] = "DROP FOREIGN KEY " . $this->quote($index);
        return $this;
    }

    public function dropIndex(string $index): static
    {
        $this->commands[] = "DROP INDEX " . $this->quote($index);
        return $this;
    }

    // ─── Internal ─────────────────────────────────────────

    protected function addColumn(string $name, string $type): static
    {
        $this->currentColumn = [
            'name' => $name,
            'type' => $type,
            'nullable' => false,
            'default' => null,
            'has_default' => false,
            'unsigned' => false,
            'unique' => false,
            'after' => null,
            'comment' => null,
        ];
        $this->columns[] = $this->currentColumn;
        return $this;
    }

    protected function updateCurrentColumn(): void
    {
        $lastIndex = count($this->columns) - 1;
        $this->columns[$lastIndex] = $this->currentColumn;
    }

    public function getTable(): string
    {
        return $this->table;
    }
    public function getColumns(): array
    {
        return $this->columns;
    }
    public function getCommands(): array
    {
        return $this->commands;
    }
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /**
     * Quote an identifier in backticks after validating its shape. Used
     * for every column/table/index reference Blueprint emits — keeps the
     * generated DDL safe against reserved-word collisions ('order', 'key')
     * and rejects names that don't look like identifiers.
     */
    public function quote(string $identifier): string
    {
        $identifier = trim($identifier);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Invalid identifier: {$identifier}");
        }
        return '`' . $identifier . '`';
    }

    // ─── Compile to SQL ───────────────────────────────────

    public function toCreateSql(): string
    {
        $parts = [];

        foreach ($this->columns as $col) {
            $parts[] = $this->compileColumn($col);
        }

        foreach ($this->commands as $cmd) {
            $parts[] = $cmd;
        }

        foreach ($this->indexes as $idx) {
            if (is_string($idx)) {
                $idx = [
                    'cols'   => [$idx],
                    'name'   => "idx_{$this->table}_{$idx}",
                    'unique' => false,
                ];
            }
            $kind = $idx['unique'] ? 'UNIQUE INDEX' : 'INDEX';
            $cols = implode(', ', array_map([$this, 'quote'], $idx['cols']));
            $parts[] = "{$kind} {$this->quote($idx['name'])} ({$cols})";
        }

        $columnsSql = implode(",\n    ", $parts);
        $table = $this->quote($this->table);

        return "CREATE TABLE {$table} (\n    {$columnsSql}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    public function toAlterSql(): array
    {
        $statements = [];
        $table = $this->quote($this->table);

        foreach ($this->columns as $col) {
            $colSql = $this->compileColumn($col);
            $after = $col['after'] ? " AFTER " . $this->quote($col['after']) : '';
            $statements[] = "ALTER TABLE {$table} ADD COLUMN {$colSql}{$after}";
        }

        foreach ($this->commands as $cmd) {
            $verb = $this->commandNeedsAddPrefix($cmd) ? 'ADD ' : '';
            $statements[] = "ALTER TABLE {$table} {$verb}{$cmd}";
        }

        foreach ($this->indexes as $idx) {
            if (is_string($idx)) {
                $idx = [
                    'cols'   => [$idx],
                    'name'   => "idx_{$this->table}_{$idx}",
                    'unique' => false,
                ];
            }
            $kind = $idx['unique'] ? 'UNIQUE INDEX' : 'INDEX';
            $cols = implode(', ', array_map([$this, 'quote'], $idx['cols']));
            $statements[] = "ALTER TABLE {$table} ADD {$kind} {$this->quote($idx['name'])} ({$cols})";
        }

        return $statements;
    }

    /**
     * True when an ALTER command needs an "ADD " prefix. Drop/rename
     * commands already include their verb; constraint clauses (CONSTRAINT…,
     * INDEX…, UNIQUE INDEX…, PRIMARY KEY…) do not.
     */
    private function commandNeedsAddPrefix(string $cmd): bool
    {
        $upper = ltrim(strtoupper($cmd));
        foreach (['DROP ', 'RENAME ', 'MODIFY ', 'CHANGE '] as $prefix) {
            if (str_starts_with($upper, $prefix)) return false;
        }
        return true;
    }

    /**
     * Render a single column definition. Identifier is backtick-quoted.
     * Special cases:
     *   - id() inlines 'AUTO_INCREMENT PRIMARY KEY' which is already a
     *     NOT NULL by definition — no redundant 'NOT NULL' appended.
     *   - default() accepts RawExpression for SQL-function defaults
     *     (CURRENT_TIMESTAMP), bool/int as numeric, string as quoted.
     */
    protected function compileColumn(array $col): string
    {
        $sql = $this->quote($col['name']) . " {$col['type']}";

        // 'id()' contains 'PRIMARY KEY' so we don't append NOT NULL after,
        // and 'AUTO_INCREMENT' implies NOT NULL anyway.
        $isPrimaryKey = str_contains($col['type'], 'PRIMARY KEY');

        if ($col['unsigned'] && !str_contains($col['type'], 'UNSIGNED')) {
            $sql .= ' UNSIGNED';
        }

        if (!$isPrimaryKey) {
            $sql .= $col['nullable'] ? ' NULL' : ' NOT NULL';
        }

        if (!empty($col['has_default'])) {
            $sql .= ' DEFAULT ' . $this->compileDefault($col['default']);
        }

        if ($col['unique'] && !$isPrimaryKey) $sql .= ' UNIQUE';
        if ($col['comment']) {
            $escaped = str_replace("'", "''", $col['comment']);
            $sql .= " COMMENT '{$escaped}'";
        }

        return $sql;
    }

    /**
     * Render a DEFAULT literal. Booleans → 1/0 (MySQL TINYINT-friendly),
     * null → NULL, RawExpression → inlined verbatim (so users can pass
     * CURRENT_TIMESTAMP / NOW() via DB::raw or useCurrent()), strings →
     * single-quoted with safe escaping.
     */
    protected function compileDefault(mixed $value): string
    {
        if ($value === null) return 'NULL';
        if ($value instanceof RawExpression) return (string) $value;
        if (is_bool($value)) return $value ? '1' : '0';
        if (is_int($value) || is_float($value)) return (string) $value;
        $escaped = str_replace("'", "''", (string) $value);
        return "'{$escaped}'";
    }
}
