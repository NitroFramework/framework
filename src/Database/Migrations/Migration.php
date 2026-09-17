<?php

namespace Nitro\Database\Migrations;

/**
 * Base for migrations that resolve the schema builder themselves.
 *
 *   return new class extends Migration {
 *       public function up(): void
 *       {
 *           Schema::create('posts', function (Blueprint $table) { ... });
 *       }
 *   };
 *
 * The runner also accepts a plain anonymous class whose up()/down() declare a
 * SchemaBuilder parameter and receive it directly; both forms run side by side.
 */
abstract class Migration
{
    /**
     * The connection this migration runs against, or null for the default.
     */
    protected ?string $connection = null;

    public function getConnection(): ?string
    {
        return $this->connection;
    }

    /** Whether this migration should run inside a transaction. */
    public function withinTransaction(): bool
    {
        return true;
    }

    abstract public function up(): void;

    public function down(): void
    {
        // Not every migration is reversible; overriding is optional.
    }
}
