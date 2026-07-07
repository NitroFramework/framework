<?php

namespace Tests\Unit\Database;

use Nitro\Database\DB;
use Nitro\Database\Query\Grammar\SqliteGrammar;
use Nitro\Database\Schema\SchemaBuilder as Schema;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end SQLite support: the driver builds a sqlite DSN, the schema builder
 * emits SQLite-correct DDL (INTEGER PRIMARY KEY AUTOINCREMENT, no ENGINE,
 * separate CREATE INDEX), and queries run — all against an in-memory database.
 */
class SqliteSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite required');
        }
        DB::configure(['driver' => 'sqlite', 'database' => ':memory:']);
    }

    protected function tearDown(): void
    {
        DB::configure(['driver' => 'sqlite', 'database' => ':memory:']);
    }

    public function test_sqlite_driver_selects_sqlite_grammar(): void
    {
        $this->assertInstanceOf(SqliteGrammar::class, DB::grammar());
    }

    public function test_create_table_with_autoincrement_string_unique_and_query(): void
    {
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->timestamp('created_at')->nullable();
        });

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasColumn('users', 'email'));

        DB::table('users')->insert(['name' => 'Ada', 'email' => 'ada@x.dev']);
        DB::table('users')->insert(['name' => 'Alan', 'email' => 'alan@x.dev']);

        $row = DB::table('users')->where('email', 'ada@x.dev')->first();
        $this->assertSame('Ada', $row->name);
        $this->assertSame(2, (int) DB::table('users')->where('id', 2)->first()->id, 'AUTOINCREMENT assigns 2 to the second row');
    }

    public function test_standalone_index_then_add_column_via_alter(): void
    {
        Schema::create('posts', function ($t) {
            $t->id();
            $t->string('title');
            $t->index('title');
        });

        // A named index exists (created via a separate CREATE INDEX on SQLite).
        $indexes = array_map(fn ($r) => (array) $r, Schema::getIndexes('posts'));
        $names = array_map(fn ($r) => $r['index_name'] ?? '', $indexes);
        $this->assertContains('idx_posts_title', $names);

        // ALTER TABLE ADD COLUMN works on SQLite.
        Schema::table('posts', function ($t) {
            $t->integer('views')->nullable();
        });
        $this->assertTrue(Schema::hasColumn('posts', 'views'));
    }

    public function test_lookup_of_missing_row_returns_null_not_an_error(): void
    {
        // The reported bug: forgot-password on a fresh DB blew up. Once the DB
        // is reachable, a missing email must simply return null.
        Schema::create('accounts', function ($t) {
            $t->id();
            $t->string('email')->unique();
        });

        $this->assertNull(DB::table('accounts')->where('email', 'nobody@x.dev')->first());
    }
}
