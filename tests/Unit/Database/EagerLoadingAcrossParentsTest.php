<?php

namespace Tests\Unit\Database;

use Nitro\Database\Connection;
use Nitro\Database\DB;
use Nitro\Database\Model\Model;
use PHPUnit\Framework\TestCase;

/**
 * Eager loading has to serve every parent, not just the first.
 *
 * This is one test per relation type because the bug it guards against turned
 * up twice in two different classes: a relation whose constructor sets limit(1)
 * for the single-parent case, whose eager path then clones that query for a
 * batched IN(…) lookup and inherits the limit. The whole query returns one row,
 * every other parent is handed null, and it reads as missing data rather than
 * as an error.
 *
 * HasOne and BelongsTo both had it. Covering all of them here means the next
 * relation added cannot quietly repeat it.
 */
class EagerLoadingAcrossParentsTest extends TestCase
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

        $conn->statement('CREATE TABLE el_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $conn->statement('CREATE TABLE el_courses (id INTEGER PRIMARY KEY AUTOINCREMENT, el_category_id INTEGER, title TEXT)');
        $conn->statement('CREATE TABLE el_summaries (id INTEGER PRIMARY KEY AUTOINCREMENT, el_course_id INTEGER, body TEXT)');
        $conn->statement('CREATE TABLE el_lessons (id INTEGER PRIMARY KEY AUTOINCREMENT, el_course_id INTEGER, title TEXT)');
        $conn->statement('CREATE TABLE el_bodies (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $conn->statement('CREATE TABLE el_body_el_course (id INTEGER PRIMARY KEY AUTOINCREMENT, el_course_id INTEGER, el_body_id INTEGER)');

        Model::clearBootedModels();

        // Three courses, each in its OWN category and with its own everything.
        // Distinct parents is the point: shared ones would mask the bug.
        foreach (['Food', 'Fire', 'Safeguarding'] as $index => $name) {
            $categoryId = (int) DB::table('el_categories')->insertGetId(['name' => $name]);

            $courseId = (int) DB::table('el_courses')->insertGetId([
                'el_category_id' => $categoryId,
                'title' => $name . ' course',
            ]);

            DB::table('el_summaries')->insert([
                'el_course_id' => $courseId,
                'body' => $name . ' summary',
            ]);

            DB::table('el_lessons')->insert(['el_course_id' => $courseId, 'title' => $name . ' lesson']);

            $bodyId = (int) DB::table('el_bodies')->insertGetId(['name' => $name . ' body']);
            DB::table('el_body_el_course')->insert(['el_course_id' => $courseId, 'el_body_id' => $bodyId]);
        }
    }

    protected function tearDown(): void
    {
        Model::clearBootedModels();
        DB::disconnect();
        parent::tearDown();
    }

    public function test_belongs_to_serves_every_parent(): void
    {
        $names = [];

        foreach (ElCourse::with('category')->get()->all() as $course) {
            $names[$course->title] = $course->category?->name;
        }

        $this->assertSame([
            'Food course' => 'Food',
            'Fire course' => 'Fire',
            'Safeguarding course' => 'Safeguarding',
        ], $names);
    }

    public function test_has_one_serves_every_parent(): void
    {
        $summaries = [];

        foreach (ElCourse::with('summary')->get()->all() as $course) {
            $summaries[$course->title] = $course->summary?->body;
        }

        $this->assertSame([
            'Food course' => 'Food summary',
            'Fire course' => 'Fire summary',
            'Safeguarding course' => 'Safeguarding summary',
        ], $summaries);
    }

    public function test_has_many_serves_every_parent(): void
    {
        foreach (ElCourse::with('lessons')->get()->all() as $course) {
            $this->assertCount(1, $course->lessons->all(), $course->title . ' lost its lessons');
        }
    }

    public function test_belongs_to_many_serves_every_parent(): void
    {
        foreach (ElCourse::with('bodies')->get()->all() as $course) {
            $this->assertCount(1, $course->bodies->all(), $course->title . ' lost its accreditations');
        }
    }

    public function test_the_reverse_direction_serves_every_parent(): void
    {
        foreach (ElCategory::with('courses')->get()->all() as $category) {
            $this->assertCount(1, $category->courses->all(), $category->name . ' lost its courses');
        }
    }
}

// ─── Fixtures ─────────────────────────────────────────────

class ElCategory extends Model
{
    protected string $table = 'el_categories';
    protected array $fillable = ['name'];
    protected bool $timestamps = false;

    public function courses(): \Nitro\Database\Model\Relations\HasMany
    {
        return $this->hasMany(ElCourse::class);
    }
}

class ElCourse extends Model
{
    protected string $table = 'el_courses';
    protected array $fillable = ['el_category_id', 'title'];
    protected bool $timestamps = false;

    public function category(): \Nitro\Database\Model\Relations\BelongsTo
    {
        return $this->belongsTo(ElCategory::class);
    }

    public function summary(): \Nitro\Database\Model\Relations\HasOne
    {
        return $this->hasOne(ElSummary::class);
    }

    public function lessons(): \Nitro\Database\Model\Relations\HasMany
    {
        return $this->hasMany(ElLesson::class);
    }

    public function bodies(): \Nitro\Database\Model\Relations\BelongsToMany
    {
        return $this->belongsToMany(ElBody::class, 'el_body_el_course');
    }
}

class ElSummary extends Model
{
    protected string $table = 'el_summaries';
    protected array $fillable = ['el_course_id', 'body'];
    protected bool $timestamps = false;
}

class ElLesson extends Model
{
    protected string $table = 'el_lessons';
    protected array $fillable = ['el_course_id', 'title'];
    protected bool $timestamps = false;
}

class ElBody extends Model
{
    protected string $table = 'el_bodies';
    protected array $fillable = ['name'];
    protected bool $timestamps = false;
}
