<?php

namespace Tests\Unit\Database;

use Nitro\Database\Connection;
use Nitro\Database\DB;
use Nitro\Database\Model\Model;
use PHPUnit\Framework\TestCase;

/**
 * Eager loading a hasOne across several parents.
 *
 * HasOne's constructor puts limit(1) on the query, which is right for one
 * parent and wrong for the batched IN(…) lookup: the clone keeps it, so the
 * whole eager query returns a single row and every parent after the first is
 * silently given null. The relation reads as "these records have no author"
 * rather than as an error.
 */
class HasOneEagerLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite required');
        }

        $conn = new class([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]) extends Connection {
            protected function buildDsn(array $c): string { return 'sqlite::memory:'; }
            protected function afterConnect(\PDO $pdo): void {}
        };

        $r = new \ReflectionClass(DB::class);
        $p = $r->getProperty('connection');
        $p->setAccessible(true);
        $p->setValue(null, $conn);
        $g = $r->getProperty('grammar');
        $g->setAccessible(true);
        $g->setValue(null, new \Nitro\Database\Query\Grammar\SqliteGrammar());

        $conn->statement('CREATE TABLE eager_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $conn->statement('CREATE TABLE eager_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, eager_user_id INTEGER, bio TEXT)');

        Model::clearBootedModels();
    }

    protected function tearDown(): void
    {
        Model::clearBootedModels();
        DB::disconnect();
        parent::tearDown();
    }

    public function test_every_parent_gets_its_own_related_row(): void
    {
        foreach (['A', 'B', 'C'] as $name) {
            $id = DB::table('eager_users')->insertGetId(['name' => $name]);
            DB::table('eager_profiles')->insert(['eager_user_id' => $id, 'bio' => "bio {$name}"]);
        }

        $loaded = [];
        foreach (EagerUser::with('profile')->get()->all() as $user) {
            $loaded[$user->name] = $user->profile?->bio;
        }

        $this->assertSame(
            ['A' => 'bio A', 'B' => 'bio B', 'C' => 'bio C'],
            $loaded,
            'eager loading a hasOne must not stop after the first row'
        );
    }
}

class EagerUser extends Model
{
    protected string $table = 'eager_users';
    protected array $fillable = ['name'];

    public function profile(): \Nitro\Database\Model\Relations\HasOne
    {
        return $this->hasOne(EagerProfile::class);
    }
}

class EagerProfile extends Model
{
    protected string $table = 'eager_profiles';
    protected array $fillable = ['eager_user_id', 'bio'];
}
