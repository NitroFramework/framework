<?php

namespace Tests\Unit\Database;

use Nitro\Database\Connection;
use Nitro\Database\DB;
use Nitro\Database\Model\Model;
use PHPUnit\Framework\TestCase;

/**
 * The rest of the Eloquent surface a real application reaches for: forceFill,
 * fresh, load/loadMissing, whereKey, whereDate, lockForUpdate, and pivot
 * management.
 */
class ModelStateAndPivotTest extends TestCase
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

        $conn->statement('CREATE TABLE pv_courses (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, secret TEXT, published_at TEXT)');
        $conn->statement('CREATE TABLE pv_bodies (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $conn->statement('CREATE TABLE pv_body_pv_course (id INTEGER PRIMARY KEY AUTOINCREMENT, pvcourse_id INTEGER, pvbody_id INTEGER, reference TEXT)');
        $conn->statement('CREATE TABLE pv_lessons (id INTEGER PRIMARY KEY AUTOINCREMENT, pvcourse_id INTEGER, title TEXT)');

        Model::clearBootedModels();
    }

    protected function tearDown(): void
    {
        Model::clearBootedModels();
        DB::disconnect();
        parent::tearDown();
    }

    // ─── forceFill ────────────────────────────────────────

    public function test_force_fill_writes_a_guarded_attribute(): void
    {
        $course = new PvCourse(['title' => 'Food Hygiene']);

        // 'secret' is not fillable — mass assignment ignores it.
        $course->fill(['secret' => 'from request']);
        $this->assertNull($course->secret);

        // forceFill is code that has already decided, not request data.
        $course->forceFill(['secret' => 'set deliberately']);
        $this->assertSame('set deliberately', $course->secret);
    }

    // ─── fresh ────────────────────────────────────────────

    public function test_fresh_rereads_without_touching_the_original(): void
    {
        $course = PvCourse::create(['title' => 'Original']);

        DB::table('pv_courses')->where('id', $course->getKey())->update(['title' => 'Changed elsewhere']);

        $fresh = $course->fresh();

        $this->assertSame('Changed elsewhere', $fresh->title);
        $this->assertSame('Original', $course->title, 'the original instance must be left alone');
    }

    public function test_fresh_on_an_unsaved_model_is_null(): void
    {
        $this->assertNull((new PvCourse(['title' => 'Never saved']))->fresh());
    }

    // ─── load / loadMissing ───────────────────────────────

    public function test_load_fetches_a_relation_after_the_query(): void
    {
        $course = PvCourse::create(['title' => 'With lessons']);
        DB::table('pv_lessons')->insert(['pvcourse_id' => $course->getKey(), 'title' => 'One']);

        $this->assertFalse($course->relationLoaded('lessons'));

        $course->load('lessons');

        $this->assertTrue($course->relationLoaded('lessons'));
        $this->assertCount(1, $course->lessons->all());
    }

    public function test_load_missing_does_not_requery_what_is_there(): void
    {
        $course = PvCourse::create(['title' => 'With lessons']);
        DB::table('pv_lessons')->insert(['pvcourse_id' => $course->getKey(), 'title' => 'One']);

        $course->load('lessons');

        // A second lesson appears after the first load. loadMissing must not
        // pick it up — that is the whole difference from load(), and it is
        // what stops a loop re-querying on every iteration.
        DB::table('pv_lessons')->insert(['pvcourse_id' => $course->getKey(), 'title' => 'Two']);

        $course->loadMissing('lessons');
        $this->assertCount(1, $course->lessons->all());

        $course->load('lessons');
        $this->assertCount(2, $course->lessons->all());
    }

    // ─── whereKey / whereDate ─────────────────────────────

    public function test_where_key_and_where_key_not(): void
    {
        $a = PvCourse::create(['title' => 'A']);
        $b = PvCourse::create(['title' => 'B']);

        $this->assertSame('A', PvCourse::query()->whereKey($a->getKey())->first()->title);
        $this->assertSame('B', PvCourse::query()->whereKeyNot($a->getKey())->first()->title);
        $this->assertCount(2, PvCourse::query()->whereKey([$a->getKey(), $b->getKey()])->get()->all());
    }

    public function test_where_date_matches_a_whole_day(): void
    {
        PvCourse::create(['title' => 'Morning', 'published_at' => '2026-09-13 09:15:00']);
        PvCourse::create(['title' => 'Evening', 'published_at' => '2026-09-13 23:45:00']);
        PvCourse::create(['title' => 'Tomorrow', 'published_at' => '2026-09-14 00:30:00']);

        // A plain = against '2026-09-13' would match only a row stored at
        // exactly midnight, which is none of these.
        $titles = PvCourse::query()->whereDate('published_at', '2026-09-13')->pluck('title');

        $this->assertSame(['Morning', 'Evening'], array_values($titles));
    }

    // ─── lockForUpdate ────────────────────────────────────

    public function test_lock_for_update_runs_on_sqlite(): void
    {
        PvCourse::create(['title' => 'Locked']);

        // SQLite has no FOR UPDATE and needs none — its write transaction locks
        // the database. The point is that the same code runs on both engines
        // rather than throwing here.
        $row = DB::transaction(fn () => PvCourse::query()->lockForUpdate()->first());

        $this->assertSame('Locked', $row->title);
    }

    // ─── Pivot management ─────────────────────────────────

    public function test_attach_and_detach(): void
    {
        $course = PvCourse::create(['title' => 'Food Hygiene']);
        $cg = PvBody::create(['name' => 'City & Guilds']);
        $cpd = PvBody::create(['name' => 'CPD']);

        $course->bodies()->attach([$cg->getKey(), $cpd->getKey()]);
        $this->assertCount(2, $course->bodies()->get()->all());

        $course->bodies()->detach($cpd->getKey());
        $this->assertCount(1, $course->bodies()->get()->all());

        $course->bodies()->detach();
        $this->assertCount(0, $course->bodies()->get()->all());
    }

    public function test_attach_carries_pivot_data(): void
    {
        $course = PvCourse::create(['title' => 'Food Hygiene']);
        $body = PvBody::create(['name' => 'City & Guilds']);

        $course->bodies()->attach([$body->getKey() => ['reference' => 'CG-1234']]);

        $this->assertSame('CG-1234', DB::table('pv_body_pv_course')->first()->reference);
    }

    public function test_sync_attaches_detaches_and_reports_what_changed(): void
    {
        $course = PvCourse::create(['title' => 'Food Hygiene']);
        $a = PvBody::create(['name' => 'A']);
        $b = PvBody::create(['name' => 'B']);
        $c = PvBody::create(['name' => 'C']);

        $course->bodies()->attach([$a->getKey(), $b->getKey()]);

        $changes = $course->bodies()->sync([$b->getKey(), $c->getKey()]);

        $this->assertSame([$c->getKey()], $changes['attached']);
        $this->assertSame([(string) $a->getKey()], $changes['detached']);

        $names = array_map(fn ($body) => $body->name, $course->bodies()->get()->all());
        sort($names);
        $this->assertSame(['B', 'C'], $names);
    }

    public function test_sync_without_detaching_leaves_the_rest_alone(): void
    {
        $course = PvCourse::create(['title' => 'Food Hygiene']);
        $a = PvBody::create(['name' => 'A']);
        $b = PvBody::create(['name' => 'B']);

        $course->bodies()->attach([$a->getKey()]);
        $course->bodies()->syncWithoutDetaching([$b->getKey()]);

        $this->assertCount(2, $course->bodies()->get()->all());
    }

    public function test_sync_does_not_reattach_what_is_already_there(): void
    {
        $course = PvCourse::create(['title' => 'Food Hygiene']);
        $a = PvBody::create(['name' => 'A']);

        $course->bodies()->attach([$a->getKey()]);
        $changes = $course->bodies()->sync([$a->getKey()]);

        $this->assertSame([], $changes['attached']);
        $this->assertSame(1, DB::table('pv_body_pv_course')->count(), 'sync must not duplicate a pivot row');
    }
}

// ─── Fixtures ─────────────────────────────────────────────

class PvCourse extends Model
{
    protected string $table = 'pv_courses';
    protected array $fillable = ['title', 'published_at'];
    protected bool $timestamps = false;

    public function bodies(): \Nitro\Database\Model\Relations\BelongsToMany
    {
        return $this->belongsToMany(PvBody::class, 'pv_body_pv_course');
    }

    public function lessons(): \Nitro\Database\Model\Relations\HasMany
    {
        return $this->hasMany(PvLesson::class);
    }
}

class PvBody extends Model
{
    protected string $table = 'pv_bodies';
    protected array $fillable = ['name'];
    protected bool $timestamps = false;
}

class PvLesson extends Model
{
    protected string $table = 'pv_lessons';
    protected array $fillable = ['pvcourse_id', 'title'];
    protected bool $timestamps = false;
}
