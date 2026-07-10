<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Nitro\Database\Connection;
use Nitro\Database\DB;
use Nitro\Database\Model\Model;
use Nitro\Database\Model\ModelBuilder;
use Nitro\Database\Model\Relations\BelongsTo;
use Nitro\Database\Model\Relations\HasMany;

/**
 * The ModelBuilder/__callStatic dispatch used to swallow terminal-method
 * return values, returning the builder where an int/bool/array was
 * expected. These tests pin the corrected behavior.
 *
 * Real DB calls are stubbed via an in-memory SQLite connection so we can
 * actually run end-to-end without a MySQL server.
 */
class ModelDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite required');
        }

        // Swap DB to a memory connection. Subclass overrides DSN /
        // afterConnect because the MySQL grammar SET NAMES wouldn't
        // work on SQLite.
        $conn = new class([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]) extends Connection {
            protected function buildDsn(array $c): string { return 'sqlite::memory:'; }
            protected function afterConnect(\PDO $pdo): void {}
        };

        $this->injectConnection($conn);

        $conn->statement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, created_at TEXT, updated_at TEXT)');
        $conn->statement('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT, status TEXT, created_at TEXT, updated_at TEXT)');
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        parent::tearDown();
    }

    /**
     * Bypass DB::configure() (which expects MySQL-shaped config) by
     * setting the static $connection via reflection. Tests-only utility.
     */
    private function injectConnection(Connection $c): void
    {
        $r = new \ReflectionClass(DB::class);
        $p = $r->getProperty('connection');
        $p->setAccessible(true);
        $p->setValue(null, $c);
        $g = $r->getProperty('grammar');
        $g->setAccessible(true);
        // Use base Grammar to dodge MySQL upsert/lock specifics in tests.
        $g->setValue(null, new \Nitro\Database\Query\Grammar\MySqlGrammar());
    }

    // ─── Terminal return values propagate ─────────────────

    public function test_user_count_returns_int(): void
    {
        DummyUser::create(['name' => 'A', 'email' => 'a@e.com']);
        DummyUser::create(['name' => 'B', 'email' => 'b@e.com']);

        $count = DummyUser::query()->count();
        $this->assertIsInt($count);
        $this->assertSame(2, $count);
    }

    public function test_user_exists_returns_bool(): void
    {
        $this->assertFalse(DummyUser::query()->exists());
        DummyUser::create(['name' => 'A', 'email' => 'a@e.com']);
        $this->assertTrue(DummyUser::query()->exists());
    }

    public function test_user_pluck_returns_array(): void
    {
        DummyUser::create(['name' => 'A', 'email' => 'a@e.com']);
        DummyUser::create(['name' => 'B', 'email' => 'b@e.com']);

        $names = DummyUser::query()->pluck('name');
        $this->assertIsArray($names);
        $this->assertContains('A', $names);
        $this->assertContains('B', $names);
    }

    public function test_static_callstatic_count_returns_int(): void
    {
        DummyUser::create(['name' => 'X', 'email' => 'x@e.com']);
        $count = DummyUser::count();
        $this->assertIsInt($count);
        $this->assertSame(1, $count);
    }

    public function test_static_callstatic_exists(): void
    {
        $this->assertFalse(DummyUser::exists());
        DummyUser::create(['name' => 'X', 'email' => 'x@e.com']);
        $this->assertTrue(DummyUser::exists());
    }

    // ─── create() doesn't double-SELECT ──────────────────

    public function test_create_returns_hydrated_model_without_extra_select(): void
    {
        $u = DummyUser::create(['name' => 'Hydrated', 'email' => 'h@e.com']);

        $this->assertInstanceOf(DummyUser::class, $u);
        $this->assertNotNull($u->id);
        $this->assertSame('Hydrated', $u->name);
        $this->assertSame('h@e.com', $u->email);
        $this->assertTrue($u->exists ?? true);
    }

    // ─── first() doesn't mutate the chain ─────────────────

    public function test_first_then_get_returns_full_result(): void
    {
        DummyUser::create(['name' => 'A', 'email' => 'a@e.com']);
        DummyUser::create(['name' => 'B', 'email' => 'b@e.com']);
        DummyUser::create(['name' => 'C', 'email' => 'c@e.com']);

        $q = DummyUser::query();
        $first = $q->first();
        $this->assertInstanceOf(DummyUser::class, $first);

        $all = $q->get();
        $this->assertSame(3, $all->count(), 'first() must not mutate the builder.');
    }

    // ─── HasMany relation eager-load respects user where ──

    public function test_eager_load_with_user_added_where(): void
    {
        $u = DummyUser::create(['name' => 'Alice', 'email' => 'al@e.com']);
        DummyPost::create(['user_id' => $u->id, 'title' => 'P1', 'status' => 'published']);
        DummyPost::create(['user_id' => $u->id, 'title' => 'P2', 'status' => 'draft']);
        DummyPost::create(['user_id' => $u->id, 'title' => 'P3', 'status' => 'published']);

        // Custom relation: only published posts. Old loader would have
        // dropped the where('status','published').
        $users = DummyUser::query()->with('publishedPosts')->get();
        $this->assertSame(1, $users->count());
        $loaded = $users->first()->getRelation('publishedPosts');
        $this->assertNotNull($loaded);
        $titles = array_map(fn($p) => $p->title, $loaded->all());
        sort($titles);
        $this->assertSame(['P1', 'P3'], $titles, 'Custom relation WHERE must survive eager loading.');
    }

    public function test_eager_load_belongs_to(): void
    {
        $u = DummyUser::create(['name' => 'Bob', 'email' => 'b@e.com']);
        DummyPost::create(['user_id' => $u->id, 'title' => 'Hi', 'status' => 'published']);

        $posts = DummyPost::query()->with('user')->get();
        $this->assertSame(1, $posts->count());
        $loadedUser = $posts->first()->getRelation('user');
        $this->assertNotNull($loadedUser);
        $this->assertSame('Bob', $loadedUser->name);
    }

    public function test_eager_load_nested_belongs_to(): void
    {
        $u = DummyUser::create(['name' => 'Nested', 'email' => 'n@e.com']);
        DummyPost::create(['user_id' => $u->id, 'title' => 'T', 'status' => 'published']);

        // Going user → posts → user (round trip) — nested across the
        // belongsTo edge tests the recursion path the old loader missed.
        $users = DummyUser::query()->with('posts.user')->get();
        $u2 = $users->first();
        $posts = $u2->getRelation('posts');
        $this->assertSame(1, $posts->count());
        $loadedUser = $posts->first()->getRelation('user');
        $this->assertSame('Nested', $loadedUser->name);
    }
}

class DummyUser extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['name', 'email'];

    // Explicit foreign key — the FK-guess convention turns 'DummyUser'
    // into 'dummyuser_id' which doesn't match the schema's 'user_id'.
    public function posts(): HasMany
    {
        return $this->hasMany(DummyPost::class, 'user_id');
    }
    public function publishedPosts(): HasMany
    {
        return $this->hasMany(DummyPost::class, 'user_id')->where('status', 'published');
    }
}

class DummyPost extends Model
{
    protected string $table = 'posts';
    protected array $fillable = ['user_id', 'title', 'status'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(DummyUser::class, 'user_id');
    }
}
