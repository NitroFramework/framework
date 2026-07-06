<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Nitro\Database\Schema\Blueprint;
use Nitro\Database\Query\RawExpression;

/**
 * Security + correctness for Blueprint identifier handling and default
 * rendering. Old version emitted raw `name` everywhere — reserved-word
 * names broke, and `default(false)` produced syntax errors.
 */
class BlueprintSecurityTest extends TestCase
{
    public function test_columns_are_quoted(): void
    {
        $bp = new Blueprint('users');
        $bp->string('order'); // reserved word
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('`order`', $sql);
    }

    public function test_table_name_is_quoted(): void
    {
        $bp = new Blueprint('select');
        $bp->id();
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('CREATE TABLE `select`', $sql);
    }

    public function test_invalid_identifier_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $bp = new Blueprint('users');
        $bp->string('bad name'); // space — not a valid identifier
        $bp->toCreateSql();
    }

    public function test_default_false_emits_zero(): void
    {
        $bp = new Blueprint('t');
        $bp->boolean('active')->default(false);
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('DEFAULT 0', $sql);
        $this->assertStringNotContainsString("DEFAULT ''", $sql);
        $this->assertStringNotContainsString('DEFAULT  ', $sql); // double-space syntax err
    }

    public function test_default_true_emits_one(): void
    {
        $bp = new Blueprint('t');
        $bp->boolean('active')->default(true);
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('DEFAULT 1', $sql);
    }

    public function test_default_null_emits_keyword(): void
    {
        $bp = new Blueprint('t');
        $bp->string('s')->nullable()->default(null);
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('DEFAULT NULL', $sql);
    }

    public function test_default_raw_expression(): void
    {
        $bp = new Blueprint('t');
        $bp->timestamp('created_at')->default(new RawExpression('CURRENT_TIMESTAMP'));
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('DEFAULT CURRENT_TIMESTAMP', $sql);
        $this->assertStringNotContainsString("DEFAULT 'CURRENT_TIMESTAMP'", $sql);
    }

    public function test_use_current_helper(): void
    {
        $bp = new Blueprint('t');
        $bp->timestamp('created_at')->useCurrent();
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('DEFAULT CURRENT_TIMESTAMP', $sql);
    }

    public function test_default_string_escapes_single_quotes(): void
    {
        $bp = new Blueprint('t');
        $bp->string('comment')->default("it's fine");
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString("DEFAULT 'it''s fine'", $sql);
    }

    public function test_primary_columns_quoted(): void
    {
        $bp = new Blueprint('t');
        $bp->integer('a');
        $bp->integer('b');
        $bp->primary(['a', 'b']);
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('PRIMARY KEY (`a`, `b`)', $sql);
    }

    public function test_drop_column_is_quoted(): void
    {
        $bp = new Blueprint('t');
        $bp->dropColumn('order');
        $stmts = $bp->toAlterSql();
        $this->assertStringContainsString('DROP COLUMN `order`', $stmts[0]);
    }

    public function test_enum_quotes_values_with_escapes(): void
    {
        $bp = new Blueprint('t');
        $bp->enum('mood', ["o'kay", 'good']);
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString("ENUM('o''kay', 'good')", $sql);
    }

    public function test_id_does_not_have_redundant_not_null(): void
    {
        // PRIMARY KEY is NOT NULL by definition — emitting 'NOT NULL' is
        // redundant and the AUTO_INCREMENT clause requires it implicitly.
        $bp = new Blueprint('t');
        $bp->id();
        $sql = $bp->toCreateSql();
        $this->assertStringNotContainsString('PRIMARY KEY NOT NULL', $sql);
    }

    public function test_foreign_key_order_independent(): void
    {
        $bp = new Blueprint('posts');
        $bp->unsignedBigInteger('user_id');
        $bp->foreign('user_id')->on('users')->onDelete('cascade')->references('id');
        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('FOREIGN KEY (`user_id`)', $sql);
        $this->assertStringContainsString('REFERENCES `users`(`id`)', $sql);
        $this->assertStringContainsString('ON DELETE CASCADE', $sql);
        // Only one FK constraint registered — the chain rewrites the slot.
        $this->assertSame(1, substr_count($sql, 'FOREIGN KEY'));
    }
}
