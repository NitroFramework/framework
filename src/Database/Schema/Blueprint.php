<?php

namespace Nitro\Database\Schema;

use Nitro\Database\Query\RawExpression;
use Nitro\Database\Schema\Grammar\Grammar;
use Nitro\Database\Schema\Grammar\MySqlGrammar;
use Nitro\Database\Schema\Grammar\PostgresGrammar;
use Nitro\Database\Schema\Grammar\SqliteGrammar;

/**
 * Table definition DSL — describes columns, indexes and keys for schema create/alter.
 *
 * A Blueprint records what the table should look like without committing to an
 * engine's spelling of it: string(255) rather than VARCHAR(255). The
 * {@see Grammar} for the connection's driver states it in SQL, which is what
 * lets one migration run against MySQL, SQLite and Postgres alike.
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

    /** The connection driver the DDL is compiled for. */
    protected string $driver;

    protected Grammar $grammar;

    public function __construct(string $table, string $driver = 'mysql')
    {
        $this->table = $table;
        $this->driver = $driver;
        $this->grammar = static::grammarFor($driver);
    }

    /** The grammar that speaks a driver's DDL. */
    protected static function grammarFor(string $driver): Grammar
    {
        return match ($driver) {
            'sqlite' => new SqliteGrammar(),
            'pgsql', 'postgres', 'postgresql' => new PostgresGrammar(),
            default => new MySqlGrammar(),
        };
    }

    public function getGrammar(): Grammar
    {
        return $this->grammar;
    }

    protected function isSqlite(): bool
    {
        return $this->driver === 'sqlite';
    }

    // ─── Column Types ─────────────────────────────────────

    public function id(string $column = 'id'): static
    {
        return $this->addColumn($column, 'id');
    }

    public function string(string $column, int $length = 255): static
    {
        return $this->addColumn($column, 'string', ['length' => $length]);
    }

    public function char(string $column, int $length = 255): static
    {
        return $this->addColumn($column, 'char', ['length' => $length]);
    }

    public function text(string $column): static
    {
        return $this->addColumn($column, 'text');
    }

    public function tinyText(string $column): static
    {
        return $this->addColumn($column, 'tinyText');
    }

    public function mediumText(string $column): static
    {
        return $this->addColumn($column, 'mediumText');
    }

    public function longText(string $column): static
    {
        return $this->addColumn($column, 'longText');
    }

    public function integer(string $column): static
    {
        return $this->addColumn($column, 'integer');
    }

    public function tinyInteger(string $column): static
    {
        return $this->addColumn($column, 'tinyInteger');
    }

    public function smallInteger(string $column): static
    {
        return $this->addColumn($column, 'smallInteger');
    }

    public function mediumInteger(string $column): static
    {
        return $this->addColumn($column, 'mediumInteger');
    }

    public function bigInteger(string $column): static
    {
        return $this->addColumn($column, 'bigInteger');
    }

    public function unsignedBigInteger(string $column): static
    {
        return $this->addColumn($column, 'unsignedBigInteger');
    }

    public function float(string $column, int $precision = 8, int $scale = 2): static
    {
        return $this->addColumn($column, 'float', ['precision' => $precision, 'scale' => $scale]);
    }

    public function double(string $column): static
    {
        return $this->addColumn($column, 'double');
    }

    public function decimal(string $column, int $precision = 8, int $scale = 2): static
    {
        return $this->addColumn($column, 'decimal', ['precision' => $precision, 'scale' => $scale]);
    }

    public function boolean(string $column): static
    {
        return $this->addColumn($column, 'boolean');
    }

    public function date(string $column): static
    {
        return $this->addColumn($column, 'date');
    }

    /** Also reached as dateTime(), the way Laravel spells it — PHP ignores the case. */
    public function datetime(string $column): static
    {
        return $this->addColumn($column, 'datetime');
    }

    public function time(string $column): static
    {
        return $this->addColumn($column, 'time');
    }

    public function timestamp(string $column): static
    {
        return $this->addColumn($column, 'timestamp');
    }

    public function year(string $column): static
    {
        return $this->addColumn($column, 'year');
    }

    public function binary(string $column): static
    {
        return $this->addColumn($column, 'binary');
    }

    public function json(string $column): static
    {
        return $this->addColumn($column, 'json');
    }

    public function jsonb(string $column): static
    {
        return $this->addColumn($column, 'jsonb');
    }

    public function uuid(string $column = 'uuid'): static
    {
        return $this->addColumn($column, 'uuid');
    }

    public function ulid(string $column = 'ulid'): static
    {
        return $this->addColumn($column, 'ulid');
    }

    public function ipAddress(string $column = 'ip_address'): static
    {
        return $this->addColumn($column, 'ipAddress');
    }

    public function macAddress(string $column = 'mac_address'): static
    {
        return $this->addColumn($column, 'macAddress');
    }

    /** A column whose type this DSL has no name for, stated in the engine's own terms. */
    public function rawColumn(string $column, string $type): static
    {
        return $this->addColumn($column, 'raw', ['sql' => $type]);
    }

    /** @param array<int, mixed> $values */
    public function enum(string $column, array $values): static
    {
        return $this->addColumn($column, 'enum', ['allowed' => array_values($values)]);
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

    public function rememberToken(): static
    {
        return $this->string('remember_token', 100)->nullable();
    }

    /**
     * The pair of columns a polymorphic relation needs.
     *
     * Indexed together, because the two are always read together.
     */
    public function morphs(string $name): static
    {
        $this->unsignedBigInteger("{$name}_id");
        $this->string("{$name}_type");

        return $this->index(["{$name}_id", "{$name}_type"]);
    }

    public function nullableMorphs(string $name): static
    {
        $this->unsignedBigInteger("{$name}_id")->nullable();
        $this->string("{$name}_type")->nullable();

        return $this->index(["{$name}_id", "{$name}_type"]);
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
        $this->commands[] = 'PRIMARY KEY (' . $this->grammar->columnize($columns) . ')';
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
        $this->addColumn($column, 'unsignedBigInteger');
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
     * Convention used by constrained() when the table isn't passed:
     * 'user_id' → 'users', 'category_id' → 'categories'.
     *
     * Pluralised properly rather than by appending an 's'. The naive version
     * produced 'categorys', and because SQLite resolves a foreign key lazily
     * that surfaced as "no such table" on the first insert — in a seeder, a
     * long way from the migration that caused it.
     *
     * Pass the table name explicitly for anything genuinely irregular.
     */
    private function guessForeignTableName(string $column): string
    {
        return \Nitro\Support\Str::plural((string) preg_replace('/_id$/', '', $column));
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
            $this->commands[] = 'DROP COLUMN ' . $this->quote($col);
        }
        return $this;
    }

    public function renameColumn(string $from, string $to): static
    {
        $this->commands[] = 'RENAME COLUMN ' . $this->quote($from) . ' TO ' . $this->quote($to);
        return $this;
    }

    /**
     * Drop a foreign key, by name or by the columns it covers.
     *
     *   $table->dropForeign('fk_posts_category_id');
     *   $table->dropForeign(['category_id']);
     *
     * The array form is how Laravel spells it, and resolves to the name the
     * constraint was created under — see {@see ForeignKeyBuilder}.
     */
    public function dropForeign(string|array $index): static
    {
        $this->commands[] = $this->grammar->compileDropForeign(
            is_array($index) ? $this->defaultIndexName('fk', $index) : $index
        );

        return $this;
    }

    /** Drop an index, by name or by the columns it covers. */
    public function dropIndex(string|array $index): static
    {
        $name = is_array($index) ? $this->defaultIndexName('idx', $index) : $index;

        $this->commands[] = 'DROP INDEX ' . $this->quote($name);
        return $this;
    }

    /** Drop a unique index, by name or by the columns it covers. */
    public function dropUnique(string|array $index): static
    {
        $name = is_array($index) ? $this->defaultIndexName('uniq', $index) : $index;

        $this->commands[] = 'DROP INDEX ' . $this->quote($name);
        return $this;
    }

    public function dropPrimary(): static
    {
        $this->commands[] = 'DROP PRIMARY KEY';
        return $this;
    }

    public function dropTimestamps(): static
    {
        return $this->dropColumn(['created_at', 'updated_at']);
    }

    public function dropSoftDeletes(string $column = 'deleted_at'): static
    {
        return $this->dropColumn($column);
    }

    public function dropMorphs(string $name): static
    {
        return $this->dropColumn(["{$name}_id", "{$name}_type"]);
    }

    // ─── Internal ─────────────────────────────────────────

    /**
     * Record a column in the abstract.
     *
     * @param array<string, mixed> $parameters Length, precision, allowed values.
     */
    protected function addColumn(string $name, string $type, array $parameters = []): static
    {
        $this->currentColumn = [
            'name' => $name,
            'type' => $type,
            'parameters' => $parameters,
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
     * Normalize the indexes list, expanding the legacy string shorthand into
     * the ['cols','name','unique'] shape used by the compilers.
     *
     * @return array<int, array{cols: array, name: string, unique: bool}>
     */
    public function normalizedIndexes(): array
    {
        $out = [];
        foreach ($this->indexes as $idx) {
            if (is_string($idx)) {
                $idx = ['cols' => [$idx], 'name' => "idx_{$this->table}_{$idx}", 'unique' => false];
            }
            $out[] = $idx;
        }

        return $out;
    }

    /** Enclose an identifier the way this connection's engine does. */
    public function quote(string $identifier): string
    {
        return $this->grammar->quote($identifier);
    }

    // ─── Compile to SQL ───────────────────────────────────

    public function toCreateSql(): string
    {
        return $this->grammar->compileCreate($this);
    }

    /**
     * Statements to run immediately after toCreateSql() — the CREATE INDEX
     * statements, for an engine that will not hold them inside the CREATE.
     *
     * @return array<int, string>
     */
    public function postCreateStatements(): array
    {
        return $this->grammar->compilePostCreate($this);
    }

    /** @return array<int, string> */
    public function toAlterSql(): array
    {
        return $this->grammar->compileAlter($this);
    }
}
