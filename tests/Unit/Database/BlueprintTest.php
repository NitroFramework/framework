<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Nitro\Database\Schema\Blueprint;

class BlueprintTest extends TestCase
{
    // =========================================================================
    // CREATE TABLE — Basic
    // =========================================================================

    public function test_create_table_basic(): void
    {
        $bp = new Blueprint('users');
        $bp->id();
        $bp->string('name');
        $bp->string('email');
        $bp->timestamps();

        $sql = $bp->toCreateSql();

        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('users', $sql);
        $this->assertStringContainsString('name', $sql);
        $this->assertStringContainsString('email', $sql);
        $this->assertStringContainsString('created_at', $sql);
        $this->assertStringContainsString('updated_at', $sql);
    }

    public function test_table_name(): void
    {
        $bp = new Blueprint('posts');
        $this->assertSame('posts', $bp->getTable());
    }

    // =========================================================================
    // Column Types
    // =========================================================================

    public function test_column_id(): void
    {
        $bp = new Blueprint('t');
        $bp->id();
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('id', $sql);
        $this->assertStringContainsString('AUTO_INCREMENT', $sql);
    }

    public function test_column_custom_id_name(): void
    {
        $bp = new Blueprint('t');
        $bp->id('user_id');
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('user_id', $sql);
    }

    public function test_column_string_default_length(): void
    {
        $bp = new Blueprint('t');
        $bp->string('name');
        $this->assertStringContainsString('VARCHAR(255)', $bp->toCreateSql());
    }

    public function test_column_string_custom_length(): void
    {
        $bp = new Blueprint('t');
        $bp->string('code', 10);
        $this->assertStringContainsString('VARCHAR(10)', $bp->toCreateSql());
    }

    public function test_column_text(): void
    {
        $bp = new Blueprint('t');
        $bp->text('body');
        $this->assertStringContainsString('TEXT', $bp->toCreateSql());
    }

    public function test_column_long_text(): void
    {
        $bp = new Blueprint('t');
        $bp->longText('content');
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('content', $sql);
    }

    public function test_column_medium_text(): void
    {
        $bp = new Blueprint('t');
        $bp->mediumText('description');
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('description', $sql);
    }

    public function test_column_integer(): void
    {
        $bp = new Blueprint('t');
        $bp->integer('age');
        $this->assertStringContainsString('INT', $bp->toCreateSql());
    }

    public function test_column_big_integer(): void
    {
        $bp = new Blueprint('t');
        $bp->bigInteger('views');
        $this->assertStringContainsString('BIGINT', $bp->toCreateSql());
    }

    public function test_column_small_integer(): void
    {
        $bp = new Blueprint('t');
        $bp->smallInteger('rank');
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('rank', $sql);
    }

    public function test_column_medium_integer(): void
    {
        $bp = new Blueprint('t');
        $bp->mediumInteger('score');
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('score', $sql);
    }

    public function test_column_tiny_integer(): void
    {
        $bp = new Blueprint('t');
        $bp->tinyInteger('flag');
        $this->assertStringContainsString('TINYINT', $bp->toCreateSql());
    }

    public function test_column_unsigned_big_integer(): void
    {
        $bp = new Blueprint('t');
        $bp->unsignedBigInteger('counter');
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('BIGINT', $sql);
        $this->assertStringContainsString('UNSIGNED', $sql);
    }

    public function test_column_boolean(): void
    {
        $bp = new Blueprint('t');
        $bp->boolean('active');
        $this->assertStringContainsString('TINYINT(1)', $bp->toCreateSql());
    }

    public function test_column_decimal(): void
    {
        $bp = new Blueprint('t');
        $bp->decimal('price', 10, 2);
        $this->assertStringContainsString('DECIMAL(10,2)', $bp->toCreateSql());
    }

    public function test_column_float(): void
    {
        $bp = new Blueprint('t');
        $bp->float('rating');
        $this->assertStringContainsString('FLOAT', $bp->toCreateSql());
    }

    public function test_column_date(): void
    {
        $bp = new Blueprint('t');
        $bp->date('birth_date');
        $this->assertStringContainsString('DATE', $bp->toCreateSql());
    }

    public function test_column_datetime(): void
    {
        $bp = new Blueprint('t');
        $bp->datetime('event_at');
        $this->assertStringContainsString('DATETIME', $bp->toCreateSql());
    }

    public function test_column_timestamp(): void
    {
        $bp = new Blueprint('t');
        $bp->timestamp('logged_at');
        $this->assertStringContainsString('TIMESTAMP', $bp->toCreateSql());
    }

    public function test_column_json(): void
    {
        $bp = new Blueprint('t');
        $bp->json('metadata');
        $this->assertStringContainsString('JSON', $bp->toCreateSql());
    }

    public function test_column_enum(): void
    {
        $bp = new Blueprint('t');
        $bp->enum('status', ['active', 'inactive']);
        $this->assertStringContainsString("ENUM('active', 'inactive')", $bp->toCreateSql());
    }

    public function test_column_char(): void
    {
        $bp = new Blueprint('t');
        $bp->char('code', 3);
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('code', $sql);
    }

    public function test_column_binary(): void
    {
        $bp = new Blueprint('t');
        $bp->binary('data');
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('data', $sql);
    }

    public function test_column_year(): void
    {
        $bp = new Blueprint('t');
        $bp->year('birth_year');
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('birth_year', $sql);
    }

    public function test_column_time(): void
    {
        $bp = new Blueprint('t');
        $bp->time('start_time');
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('start_time', $sql);
    }

    // =========================================================================
    // Column Modifiers
    // =========================================================================

    public function test_column_nullable(): void
    {
        $bp = new Blueprint('t');
        $bp->string('phone')->nullable();
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('NULL', $sql);
        $this->assertStringNotContainsString('NOT NULL', $sql);
    }

    public function test_column_not_null_by_default(): void
    {
        $bp = new Blueprint('t');
        $bp->string('name');
        $this->assertStringContainsString('NOT NULL', $bp->toCreateSql());
    }

    public function test_column_unsigned(): void
    {
        $bp = new Blueprint('t');
        $bp->integer('age')->unsigned();
        $this->assertStringContainsString('UNSIGNED', $bp->toCreateSql());
    }

    public function test_column_unique(): void
    {
        $bp = new Blueprint('t');
        $bp->string('email')->unique();
        $this->assertStringContainsString('UNIQUE', $bp->toCreateSql());
    }

    public function test_column_after(): void
    {
        $bp = new Blueprint('t');
        $bp->string('phone')->after('email');
        $statements = $bp->toAlterSql();
        $this->assertStringContainsString('AFTER', $statements[0]);
    }

    public function test_column_comment(): void
    {
        $bp = new Blueprint('t');
        $bp->string('email')->comment('User email address');
        $this->assertStringContainsString("COMMENT 'User email address'", $bp->toCreateSql());
    }

    // =========================================================================
    // Timestamps & Soft Deletes
    // =========================================================================

    public function test_timestamps(): void
    {
        $bp = new Blueprint('t');
        $bp->timestamps();
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('created_at', $sql);
        $this->assertStringContainsString('updated_at', $sql);
    }

    public function test_soft_deletes(): void
    {
        $bp = new Blueprint('t');
        $bp->softDeletes();
        $this->assertStringContainsString('deleted_at', $bp->toCreateSql());
    }

    public function test_soft_deletes_custom_column(): void
    {
        $bp = new Blueprint('t');
        $bp->softDeletes('removed_at');
        $this->assertStringContainsString('removed_at', $bp->toCreateSql());
    }

    // =========================================================================
    // Indexes
    // =========================================================================

    public function test_primary_key(): void
    {
        $bp = new Blueprint('t');
        $bp->unsignedBigInteger('user_id');
        $bp->unsignedBigInteger('role_id');
        $bp->primary(['user_id', 'role_id']);
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('PRIMARY KEY', $sql);
    }

    public function test_index(): void
    {
        $bp = new Blueprint('t');
        $bp->string('slug');
        $bp->index('slug');
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('INDEX', $sql);
        $this->assertStringContainsString('slug', $sql);
    }

    public function test_unique_index(): void
    {
        $bp = new Blueprint('t');
        $bp->string('email');
        $bp->unique('email');
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('UNIQUE', $sql);
    }

    // =========================================================================
    // Foreign Keys
    // =========================================================================

    public function test_foreign_key(): void
    {
        $bp = new Blueprint('posts');
        $fk = $bp->foreign('user_id');
        $this->assertNotNull($fk);
    }

    // =========================================================================
    // Blueprint Metadata
    // =========================================================================

    public function test_get_columns(): void
    {
        $bp = new Blueprint('users');
        $bp->id();
        $bp->string('name');
        $bp->string('email');
        $this->assertCount(3, $bp->getColumns());
    }

    public function test_get_commands_from_drop(): void
    {
        $bp = new Blueprint('users');
        $bp->dropColumn('name');
        $commands = $bp->getCommands();
        $this->assertNotEmpty($commands);
    }

    // =========================================================================
    // ALTER TABLE — Add Columns
    // =========================================================================

    public function test_alter_add_column(): void
    {
        $bp = new Blueprint('users');
        $bp->string('phone');
        $statements = $bp->toAlterSql();
        $this->assertNotEmpty($statements);
        $this->assertStringContainsString('ALTER TABLE', $statements[0]);
        $this->assertStringContainsString('phone', $statements[0]);
    }

    public function test_alter_add_multiple_columns(): void
    {
        $bp = new Blueprint('users');
        $bp->string('phone');
        $bp->integer('age');
        $statements = $bp->toAlterSql();
        $this->assertCount(2, $statements);
    }

    // =========================================================================
    // ALTER TABLE — Drop
    // =========================================================================

    public function test_alter_drop_column(): void
    {
        $bp = new Blueprint('users');
        $bp->dropColumn('name');
        $statements = $bp->toAlterSql();
        $this->assertNotEmpty($statements);
        $this->assertStringContainsString('DROP', $statements[0]);
        $this->assertStringContainsString('name', $statements[0]);
    }

    public function test_alter_drop_multiple_columns(): void
    {
        $bp = new Blueprint('users');
        $bp->dropColumn(['name', 'email']);
        $statements = $bp->toAlterSql();
        $this->assertNotEmpty($statements);
    }

    public function test_alter_drop_index(): void
    {
        $bp = new Blueprint('users');
        $bp->dropIndex('idx_email');
        $statements = $bp->toAlterSql();
        $this->assertNotEmpty($statements);
        $this->assertStringContainsString('DROP INDEX', $statements[0]);
    }

    public function test_alter_drop_foreign(): void
    {
        $bp = new Blueprint('posts');
        $bp->dropForeign('fk_user_id');
        $statements = $bp->toAlterSql();
        $this->assertNotEmpty($statements);
        $this->assertStringContainsString('DROP FOREIGN KEY', $statements[0]);
    }

    // =========================================================================
    // ALTER TABLE — Rename
    // =========================================================================

    public function test_alter_rename_column(): void
    {
        $bp = new Blueprint('users');
        $bp->renameColumn('name', 'full_name');
        $statements = $bp->toAlterSql();
        $this->assertNotEmpty($statements);
        $this->assertStringContainsString('RENAME COLUMN', $statements[0]);
        $this->assertStringContainsString('name', $statements[0]);
        $this->assertStringContainsString('full_name', $statements[0]);
    }

    // =========================================================================
    // Full Realistic Table
    // =========================================================================

    public function test_full_posts_table(): void
    {
        $bp = new Blueprint('posts');
        $bp->id();
        $bp->string('title');
        $bp->text('body');
        $bp->enum('status', ['draft', 'published']);
        $bp->boolean('featured');
        $bp->decimal('rating', 3, 2);
        $bp->json('tags');
        $bp->timestamps();
        $bp->softDeletes();

        $sql = $bp->toCreateSql();

        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('posts', $sql);
        $this->assertStringContainsString('title', $sql);
        $this->assertStringContainsString('TEXT', $sql);
        $this->assertStringContainsString("ENUM('draft', 'published')", $sql);
        $this->assertStringContainsString('TINYINT(1)', $sql);
        $this->assertStringContainsString('DECIMAL(3,2)', $sql);
        $this->assertStringContainsString('JSON', $sql);
        $this->assertStringContainsString('created_at', $sql);
        $this->assertStringContainsString('deleted_at', $sql);
    }

    public function test_full_pivot_table(): void
    {
        $bp = new Blueprint('role_user');
        $bp->unsignedBigInteger('user_id');
        $bp->unsignedBigInteger('role_id');
        $bp->primary(['user_id', 'role_id']);
        $bp->timestamps();

        $sql = $bp->toCreateSql();

        $this->assertStringContainsString('user_id', $sql);
        $this->assertStringContainsString('role_id', $sql);
        $this->assertStringContainsString('PRIMARY KEY', $sql);
    }
}