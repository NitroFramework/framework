<?php

namespace Tests\Unit\Database;

use Nitro\Database\Connection;
use Nitro\Database\DB;
use Nitro\Database\Model\BaseModel;
use Nitro\Database\Model\SoftDeletes;
use PHPUnit\Framework\TestCase;

/**
 * Soft deletes (scope, restore, forceDelete, withTrashed/onlyTrashed) and model
 * lifecycle events, on in-memory SQLite.
 */
class SoftDeletesAndEventsTest extends TestCase
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
        $conn->statement('CREATE TABLE soft_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, deleted_at TEXT, created_at TEXT, updated_at TEXT)');
        $conn->statement('CREATE TABLE event_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, created_at TEXT, updated_at TEXT)');

        SoftPost::flushEventListeners();
        EventPost::flushEventListeners();
    }

    protected function tearDown(): void
    {
        SoftPost::flushEventListeners();
        EventPost::flushEventListeners();
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

    // ─── Soft deletes ─────────────────────────────────────────────────────

    public function test_delete_hides_row_from_default_queries(): void
    {
        $p = SoftPost::create(['title' => 'Hello']);
        $this->assertSame(1, SoftPost::query()->count());

        $p->delete();

        $this->assertTrue($p->trashed());
        $this->assertSame(0, SoftPost::query()->count(), 'Trashed rows are hidden by default.');
        $this->assertSame(1, SoftPost::withTrashed()->count());
        $this->assertSame(1, SoftPost::onlyTrashed()->count());
    }

    public function test_restore_brings_row_back(): void
    {
        $p = SoftPost::create(['title' => 'X']);
        $p->delete();
        $this->assertSame(0, SoftPost::query()->count());

        $p->restore();

        $this->assertFalse($p->trashed());
        $this->assertSame(1, SoftPost::query()->count());
    }

    public function test_force_delete_removes_permanently(): void
    {
        $p = SoftPost::create(['title' => 'Gone']);
        $p->forceDelete();

        $this->assertSame(0, SoftPost::withTrashed()->count());
    }

    // ─── Model events ─────────────────────────────────────────────────────

    public function test_creating_and_created_fire(): void
    {
        $fired = [];
        EventPost::creating(function ($m) use (&$fired) { $fired[] = 'creating'; });
        EventPost::created(function ($m) use (&$fired) { $fired[] = 'created'; });
        EventPost::saved(function ($m) use (&$fired) { $fired[] = 'saved'; });

        EventPost::create(['title' => 'A']);

        $this->assertSame(['creating', 'created', 'saved'], $fired);
    }

    public function test_creating_returning_false_aborts_insert(): void
    {
        EventPost::creating(fn ($m) => false);

        $post = new EventPost(['title' => 'Nope']);
        $result = $post->save();

        $this->assertFalse($result);
        $this->assertSame(0, EventPost::query()->count());
    }

    public function test_updating_and_deleting_fire(): void
    {
        $events = [];
        EventPost::updating(function ($m) use (&$events) { $events[] = 'updating'; });
        EventPost::deleting(function ($m) use (&$events) { $events[] = 'deleting'; });

        $p = EventPost::create(['title' => 'A']);
        $p->update(['title' => 'B']);
        $p->delete();

        $this->assertSame(['updating', 'deleting'], $events);
    }

    public function test_observer_methods_are_registered(): void
    {
        EventPost::observe(new class {
            public array $seen = [];
            public function creating($m): void { $GLOBALS['__obs_creating'] = true; }
        });
        $GLOBALS['__obs_creating'] = false;

        EventPost::create(['title' => 'Z']);

        $this->assertTrue($GLOBALS['__obs_creating']);
        unset($GLOBALS['__obs_creating']);
    }
}

class SoftPost extends BaseModel
{
    use SoftDeletes;

    protected string $table = 'soft_posts';
    protected array $fillable = ['title'];
}

class EventPost extends BaseModel
{
    protected string $table = 'event_posts';
    protected array $fillable = ['title'];
}
