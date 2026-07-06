<?php

namespace Nitro\Database\Schema;

/**
 * Builds a FOREIGN KEY constraint clause and registers it once with the
 * Blueprint. Every modifier (references/on/onDelete/onUpdate) rebuilds the
 * clause and replaces the previously-registered slot, so call order is
 * irrelevant — these are equivalent:
 *
 *   $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
 *   $table->foreign('user_id')->on('users')->onDelete('cascade')->references('id');
 *
 * The clause is only registered with the Blueprint once BOTH the
 * referenced table and column are known.
 */
class ForeignKeyBuilder
{
    private Blueprint $blueprint;
    private string $column;
    private string $referencesTable = '';
    private string $referencesColumn = '';
    private string $onDelete = 'RESTRICT';
    private string $onUpdate = 'RESTRICT';

    /**
     * Index of this builder's command in the Blueprint's command array,
     * or null if build() hasn't appended one yet. Used so chained calls
     * REWRITE the same row instead of appending duplicate CONSTRAINT
     * statements with the same name.
     */
    private ?int $commandIndex = null;

    public function __construct(Blueprint $blueprint, string $column)
    {
        $this->blueprint = $blueprint;
        $this->column = $column;
    }

    public function references(string $column): static
    {
        $this->referencesColumn = $column;
        $this->build();
        return $this;
    }

    public function on(string $table): static
    {
        $this->referencesTable = $table;
        $this->build();
        return $this;
    }

    public function onDelete(string $action): static
    {
        $this->onDelete = strtoupper($action);
        $this->build();
        return $this;
    }

    public function onUpdate(string $action): static
    {
        $this->onUpdate = strtoupper($action);
        $this->build();
        return $this;
    }

    public function cascadeOnDelete(): static  { return $this->onDelete('CASCADE'); }
    public function nullOnDelete(): static     { return $this->onDelete('SET NULL'); }
    public function cascadeOnUpdate(): static  { return $this->onUpdate('CASCADE'); }
    public function restrictOnDelete(): static { return $this->onDelete('RESTRICT'); }

    /**
     * Emit (or update) the CONSTRAINT clause on the Blueprint. Builds
     * once on the first call that completes the (table, column) pair,
     * and rewrites the same slot on every subsequent call.
     */
    private function build(): void
    {
        if (!($this->referencesTable && $this->referencesColumn)) {
            return;
        }

        $name = "fk_{$this->blueprint->getTable()}_{$this->column}";
        $sql  = "CONSTRAINT " . $this->blueprint->quote($name)
              . " FOREIGN KEY (" . $this->blueprint->quote($this->column) . ") "
              . "REFERENCES " . $this->blueprint->quote($this->referencesTable)
              . "(" . $this->blueprint->quote($this->referencesColumn) . ") "
              . "ON DELETE {$this->onDelete} ON UPDATE {$this->onUpdate}";

        if ($this->commandIndex === null) {
            $this->commandIndex = $this->blueprint->addForeignCommand($sql);
        } else {
            $this->blueprint->replaceCommand($this->commandIndex, $sql);
        }
    }
}
