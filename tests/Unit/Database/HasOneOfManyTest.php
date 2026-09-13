<?php

namespace Tests\Unit\Database;

use Nitro\Database\Connection;
use Nitro\Database\DB;
use Nitro\Database\Model\Model;
use PHPUnit\Framework\TestCase;

/**
 * hasOne()->ofMany() — the one related row that wins an aggregate.
 *
 * The case that matters is a constraint inside the aggregate. Taking MAX() over
 * every row and filtering the result afterwards gives a relation that quietly
 * returns nothing as soon as a higher-numbered row exists that the filter
 * rejects, which is the normal state of affairs the moment somebody starts a
 * draft revision of a published course.
 */
class HasOneOfManyTest extends TestCase
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

        $conn->statement('CREATE TABLE courses (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)');
        $conn->statement('CREATE TABLE course_versions (id INTEGER PRIMARY KEY AUTOINCREMENT, course_id INTEGER, version INTEGER, status TEXT)');

        Model::clearBootedModels();
    }

    protected function tearDown(): void
    {
        Model::clearBootedModels();
        DB::disconnect();
        parent::tearDown();
    }

    private function course(string $title): int
    {
        return (int) DB::table('courses')->insertGetId(['title' => $title]);
    }

    private function version(int $courseId, int $version, string $status): void
    {
        DB::table('course_versions')->insert([
            'course_id' => $courseId,
            'version' => $version,
            'status' => $status,
        ]);
    }

    // ─── The constraint lives inside the aggregate ────────

    public function test_it_returns_the_highest_matching_row(): void
    {
        $id = $this->course('Food Hygiene');
        $this->version($id, 1, 'published');
        $this->version($id, 2, 'published');

        $course = OfManyCourse::find($id);

        $this->assertSame(2, (int) $course->liveVersion()->first()->version);
    }

    public function test_a_draft_revision_does_not_take_the_course_off_sale(): void
    {
        $id = $this->course('Food Hygiene');
        $this->version($id, 1, 'published');
        $this->version($id, 2, 'draft');

        // MAX(version) across every row is 2, which is the draft. Filtering
        // after the aggregate would match nothing and blank the public page.
        $live = OfManyCourse::find($id)->liveVersion()->first();

        $this->assertNotNull($live, 'the published version 1 should still be live');
        $this->assertSame(1, (int) $live->version);
    }

    public function test_a_course_with_no_matching_row_has_none(): void
    {
        $id = $this->course('Unpublished');
        $this->version($id, 1, 'draft');

        $this->assertNull(OfManyCourse::find($id)->liveVersion()->first());
    }

    public function test_oldest_of_many_takes_the_lowest(): void
    {
        $id = $this->course('Food Hygiene');
        $this->version($id, 1, 'published');
        $this->version($id, 2, 'published');

        $this->assertSame(1, (int) OfManyCourse::find($id)->firstVersion()->first()->version);
    }

    // ─── Eager loading ────────────────────────────────────

    public function test_eager_loading_picks_the_right_row_per_course(): void
    {
        $a = $this->course('A');
        $this->version($a, 1, 'published');
        $this->version($a, 2, 'published');
        $this->version($a, 3, 'draft');

        $b = $this->course('B');
        $this->version($b, 1, 'published');
        $this->version($b, 5, 'draft');

        $c = $this->course('C');
        $this->version($c, 9, 'draft');

        $courses = [];
        foreach (OfManyCourse::with('liveVersion')->get()->all() as $course) {
            $courses[$course->title] = $course->liveVersion;
        }

        $this->assertSame(2, (int) $courses['A']->version);
        $this->assertSame(1, (int) $courses['B']->version);
        $this->assertNull($courses['C'], 'a course with only a draft has no live version');
    }

    public function test_eager_loading_does_not_pair_a_row_with_the_wrong_parent(): void
    {
        // Both courses have a winning version of 2. The second query fetches
        // rows by (course_id IN …, version IN …), which matches across parents;
        // only re-checking the pair keeps them apart.
        $a = $this->course('A');
        $this->version($a, 2, 'published');

        $b = $this->course('B');
        $this->version($b, 2, 'published');

        foreach (OfManyCourse::with('liveVersion')->get()->all() as $course) {
            $this->assertSame(
                (int) $course->id,
                (int) $course->liveVersion->course_id,
                'each course should get its own version row'
            );
        }
    }

    public function test_eager_loading_matches_the_lazy_result(): void
    {
        $a = $this->course('A');
        $this->version($a, 1, 'published');
        $this->version($a, 4, 'draft');
        $this->version($a, 3, 'published');

        $lazy = OfManyCourse::find($a)->liveVersion()->first();
        $eager = OfManyCourse::with('liveVersion')->first()->liveVersion;

        $this->assertSame((int) $lazy->id, (int) $eager->id);
        $this->assertSame(3, (int) $eager->version);
    }
}

// ─── Fixtures ─────────────────────────────────────────────

class OfManyCourse extends Model
{
    protected string $table = 'courses';
    protected array $fillable = ['title'];

    public function liveVersion(): \Nitro\Database\Model\Relations\HasOneOfMany
    {
        return $this->hasOne(OfManyVersion::class, 'course_id')->ofMany(
            ['version' => 'max'],
            fn ($query) => $query->where('status', 'published'),
        );
    }

    public function firstVersion(): \Nitro\Database\Model\Relations\HasOneOfMany
    {
        return $this->hasOne(OfManyVersion::class, 'course_id')->oldestOfMany(
            'version',
            fn ($query) => $query->where('status', 'published'),
        );
    }
}

class OfManyVersion extends Model
{
    protected string $table = 'course_versions';
    protected array $fillable = ['course_id', 'version', 'status'];
}
