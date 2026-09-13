<?php

namespace Tests\Unit\Database;

use Nitro\Database\Connection;
use Nitro\Database\DB;
use Nitro\Database\Model\Model;
use PHPUnit\Framework\TestCase;

/**
 * Saving a model that was loaded from the database.
 *
 * Hydration skips copying the attributes, because most rows are only ever
 * read. The snapshot that copy would have made has to be taken before the
 * first write instead — taken any later and it captures the already-modified
 * state, so getDirty() compares the new values against themselves, finds
 * nothing, and save() writes nothing while returning true.
 *
 * That is the worst shape a bug can take: a silent no-op that reports success.
 */
class DirtyTrackingTest extends TestCase
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

        $conn->statement('CREATE TABLE counters (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, seconds INTEGER DEFAULT 0, note TEXT)');

        Model::clearBootedModels();
    }

    protected function tearDown(): void
    {
        Model::clearBootedModels();
        DB::disconnect();
        parent::tearDown();
    }

    public function test_the_first_save_after_loading_actually_writes(): void
    {
        Counter::create(['name' => 'lesson', 'seconds' => 0]);

        $loaded = Counter::query()->where('name', 'lesson')->first();
        $loaded->seconds = 15;
        $loaded->save();

        $this->assertSame(15, (int) DB::table('counters')->first()->seconds);
    }

    public function test_force_fill_on_a_loaded_model_writes(): void
    {
        Counter::create(['name' => 'lesson', 'seconds' => 0]);

        $loaded = Counter::query()->first();
        $loaded->forceFill(['seconds' => $loaded->seconds + 15])->save();

        $this->assertSame(15, (int) DB::table('counters')->first()->seconds);
    }

    public function test_a_second_save_still_writes(): void
    {
        Counter::create(['name' => 'lesson', 'seconds' => 0]);

        $loaded = Counter::query()->first();

        $loaded->forceFill(['seconds' => 15])->save();
        $loaded->forceFill(['seconds' => 30])->save();

        $this->assertSame(30, (int) DB::table('counters')->first()->seconds);
    }

    public function test_only_the_changed_columns_are_written(): void
    {
        Counter::create(['name' => 'lesson', 'seconds' => 0, 'note' => 'original']);

        $loaded = Counter::query()->first();
        $loaded->seconds = 5;

        // The snapshot has to be of the row as loaded, or everything looks
        // dirty and an unrelated concurrent change gets clobbered.
        $this->assertSame(['seconds' => 5], $loaded->getDirty());
    }

    public function test_an_untouched_model_is_not_dirty(): void
    {
        Counter::create(['name' => 'lesson', 'seconds' => 3]);

        $this->assertSame([], Counter::query()->first()->getDirty());
    }

    public function test_reading_before_writing_does_not_confuse_the_snapshot(): void
    {
        Counter::create(['name' => 'lesson', 'seconds' => 7]);

        $loaded = Counter::query()->first();

        // Reading an attribute must not be mistaken for changing one.
        $current = $loaded->seconds;
        $loaded->seconds = $current + 1;
        $loaded->save();

        $this->assertSame(8, (int) DB::table('counters')->first()->seconds);
    }
}

class Counter extends Model
{
    protected string $table = 'counters';
    protected array $fillable = ['name', 'seconds', 'note'];
    protected bool $timestamps = false;
}
