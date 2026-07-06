<?php

namespace Tests\Unit\Database;

use Nitro\Database\Connection;
use Nitro\Database\DB;
use Nitro\Database\Model\BaseModel;
use Nitro\Database\Model\ModelBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Eloquent-parity additions: firstOrCreate/updateOrCreate/firstOrNew, query
 * scopes, and accessors/mutators. DB-backed cases run on in-memory SQLite.
 */
class EloquentParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite required');
        }

        $conn = new class([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]) extends Connection {
            protected function buildDsn(array $c): string { return 'sqlite::memory:'; }
            protected function afterConnect(\PDO $pdo): void {}
        };

        $this->injectConnection($conn);
        $conn->statement('CREATE TABLE parity_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, status TEXT, created_at TEXT, updated_at TEXT)');
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        parent::tearDown();
    }

    private function injectConnection(Connection $c): void
    {
        $r = new \ReflectionClass(DB::class);
        $p = $r->getProperty('connection');
        $p->setAccessible(true);
        $p->setValue(null, $c);
        $g = $r->getProperty('grammar');
        $g->setAccessible(true);
        $g->setValue(null, new \Nitro\Database\Query\Grammar\MySqlGrammar());
    }

    // ─── firstOrCreate / updateOrCreate / firstOrNew ──────────────────────

    public function test_first_or_create_creates_then_returns_existing(): void
    {
        $a = ParityUser::firstOrCreate(['email' => 'a@b.c'], ['name' => 'Ann', 'status' => 'active']);
        $this->assertNotNull($a->id);
        $this->assertSame('Ann', $a->name);

        $b = ParityUser::firstOrCreate(['email' => 'a@b.c'], ['name' => 'Different']);
        $this->assertSame($a->id, $b->id, 'Should return the existing row, not create a second.');
        $this->assertSame(1, ParityUser::query()->count());
    }

    public function test_update_or_create_updates_existing(): void
    {
        ParityUser::create(['name' => 'Old', 'email' => 'u@b.c', 'status' => 'active']);

        $u = ParityUser::updateOrCreate(['email' => 'u@b.c'], ['name' => 'New']);
        $this->assertSame('New', $u->name);
        $this->assertSame(1, ParityUser::query()->count());
    }

    public function test_update_or_create_creates_when_absent(): void
    {
        $u = ParityUser::updateOrCreate(['email' => 'fresh@b.c'], ['name' => 'Fresh', 'status' => 'active']);
        $this->assertNotNull($u->id);
        $this->assertSame('Fresh', $u->name);
    }

    public function test_first_or_new_returns_unsaved_model(): void
    {
        $u = ParityUser::query()->firstOrNew(['email' => 'ghost@b.c'], ['name' => 'Ghost']);
        $this->assertNull($u->id, 'firstOrNew must not persist.');
        $this->assertSame('Ghost', $u->name);
        $this->assertSame(0, ParityUser::query()->count());
    }

    // ─── Query scopes ─────────────────────────────────────────────────────

    public function test_local_scope_is_applied(): void
    {
        ParityUser::create(['name' => 'A', 'email' => 'a@b.c', 'status' => 'active']);
        ParityUser::create(['name' => 'B', 'email' => 'b@b.c', 'status' => 'inactive']);
        ParityUser::create(['name' => 'C', 'email' => 'c@b.c', 'status' => 'active']);

        $this->assertSame(2, ParityUser::query()->active()->count());
        $this->assertSame(3, ParityUser::query()->count());
    }

    public function test_scope_with_argument(): void
    {
        ParityUser::create(['name' => 'A', 'email' => 'a@b.c', 'status' => 'active']);
        ParityUser::create(['name' => 'B', 'email' => 'b@b.c', 'status' => 'banned']);

        $this->assertSame(1, ParityUser::query()->ofStatus('banned')->count());
    }

    // ─── Accessors / mutators (no DB) ─────────────────────────────────────

    public function test_accessor_computes_value(): void
    {
        $u = new ParityUser(['name' => 'bob']);
        $this->assertSame('BOB', $u->name_upper);
    }

    public function test_mutator_transforms_on_set(): void
    {
        $u = new ParityUser();
        $u->secret = 'abc';
        $this->assertSame('MUTATED:abc', $u->secret);
    }
}

class ParityUser extends BaseModel
{
    protected string $table = 'parity_users';
    protected array $fillable = ['name', 'email', 'status', 'secret'];

    public function scopeActive(ModelBuilder $query): void
    {
        $query->where('status', 'active');
    }

    public function scopeOfStatus(ModelBuilder $query, string $status): void
    {
        $query->where('status', $status);
    }

    public function getNameUpperAttribute(): string
    {
        return strtoupper((string) $this->getAttribute('name'));
    }

    public function setSecretAttribute(mixed $value): void
    {
        $this->attributes['secret'] = 'MUTATED:' . $value;
    }
}
