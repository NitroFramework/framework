<?php

namespace Tests\Unit\Database;

use Nitro\Database\Connection;
use Nitro\Database\DB;
use Nitro\Database\Model\Model;
use PHPUnit\Framework\TestCase;

/**
 * whereHas() and withCount(): constraining and counting by what a relation
 * contains.
 *
 * Both compile to a correlated subquery rather than a join, which is what
 * keeps them composable — two of them do not multiply rows the way two joins
 * would, and there are tests here for exactly that.
 */
class QueriesRelationshipsTest extends TestCase
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

        $conn->statement('CREATE TABLE rel_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $conn->statement('CREATE TABLE rel_courses (id INTEGER PRIMARY KEY AUTOINCREMENT, rel_category_id INTEGER, title TEXT, status TEXT)');
        $conn->statement('CREATE TABLE rel_versions (id INTEGER PRIMARY KEY AUTOINCREMENT, rel_course_id INTEGER, version INTEGER, status TEXT)');

        Model::clearBootedModels();
    }

    protected function tearDown(): void
    {
        Model::clearBootedModels();
        DB::disconnect();
        parent::tearDown();
    }

    private function course(int $categoryId, string $title, string $status = 'published'): int
    {
        return (int) DB::table('rel_courses')->insertGetId([
            'rel_category_id' => $categoryId,
            'title' => $title,
            'status' => $status,
        ]);
    }

    private function version(int $courseId, int $number, string $status): void
    {
        DB::table('rel_versions')->insert([
            'rel_course_id' => $courseId,
            'version' => $number,
            'status' => $status,
        ]);
    }

    // ─── whereHas ─────────────────────────────────────────

    public function test_where_has_keeps_only_rows_with_a_match(): void
    {
        $cat = (int) DB::table('rel_categories')->insertGetId(['name' => 'Food']);

        $withVersion = $this->course($cat, 'Has a version');
        $this->version($withVersion, 1, 'published');

        $this->course($cat, 'Has none');

        $titles = RelCourse::query()->whereHas('versions')->pluck('title');

        $this->assertSame(['Has a version'], array_values($titles));
    }

    public function test_where_has_applies_the_constraint_inside_the_subquery(): void
    {
        $cat = (int) DB::table('rel_categories')->insertGetId(['name' => 'Food']);

        $live = $this->course($cat, 'Published version');
        $this->version($live, 1, 'published');

        $draftOnly = $this->course($cat, 'Draft only');
        $this->version($draftOnly, 1, 'draft');

        $titles = RelCourse::query()
            ->whereHas('versions', fn ($query) => $query->where('status', 'published'))
            ->pluck('title');

        $this->assertSame(['Published version'], array_values($titles));
    }

    public function test_where_doesnt_have_is_the_inverse(): void
    {
        $cat = (int) DB::table('rel_categories')->insertGetId(['name' => 'Food']);

        $withVersion = $this->course($cat, 'Has a version');
        $this->version($withVersion, 1, 'published');

        $this->course($cat, 'Has none');

        $titles = RelCourse::query()->whereDoesntHave('versions')->pluck('title');

        $this->assertSame(['Has none'], array_values($titles));
    }

    public function test_where_has_does_not_duplicate_rows(): void
    {
        // The reason this is a subquery and not a join. Three versions on one
        // course would return that course three times through a join.
        $cat = (int) DB::table('rel_categories')->insertGetId(['name' => 'Food']);

        $course = $this->course($cat, 'Three versions');
        $this->version($course, 1, 'published');
        $this->version($course, 2, 'published');
        $this->version($course, 3, 'published');

        $this->assertCount(1, RelCourse::query()->whereHas('versions')->get()->all());
    }

    public function test_where_has_combines_with_other_conditions(): void
    {
        $cat = (int) DB::table('rel_categories')->insertGetId(['name' => 'Food']);

        $live = $this->course($cat, 'Live', 'published');
        $this->version($live, 1, 'published');

        $draftCourse = $this->course($cat, 'Draft course', 'draft');
        $this->version($draftCourse, 1, 'published');

        $titles = RelCourse::query()
            ->where('status', 'published')
            ->whereHas('versions', fn ($query) => $query->where('status', 'published'))
            ->pluck('title');

        $this->assertSame(['Live'], array_values($titles));
    }

    public function test_where_has_works_through_a_belongs_to(): void
    {
        $food = (int) DB::table('rel_categories')->insertGetId(['name' => 'Food']);
        $fire = (int) DB::table('rel_categories')->insertGetId(['name' => 'Fire']);

        $this->course($food, 'Food Hygiene');
        $this->course($fire, 'Fire Safety');

        $titles = RelCourse::query()
            ->whereHas('category', fn ($query) => $query->where('name', 'Fire'))
            ->pluck('title');

        $this->assertSame(['Fire Safety'], array_values($titles));
    }

    public function test_an_unknown_relation_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RelCourse::query()->whereHas('nonsense')->get();
    }

    // ─── withCount ────────────────────────────────────────

    public function test_with_count_adds_the_count_column(): void
    {
        $cat = (int) DB::table('rel_categories')->insertGetId(['name' => 'Food']);

        $course = $this->course($cat, 'Three versions');
        $this->version($course, 1, 'published');
        $this->version($course, 2, 'draft');
        $this->version($course, 3, 'published');

        $row = RelCourse::query()->withCount('versions')->first();

        $this->assertSame(3, (int) $row->versions_count);
        $this->assertSame('Three versions', $row->title, 'the model\'s own columns must survive');
    }

    public function test_with_count_applies_a_constraint(): void
    {
        $cat = (int) DB::table('rel_categories')->insertGetId(['name' => 'Food']);

        $course = $this->course($cat, 'Mixed');
        $this->version($course, 1, 'published');
        $this->version($course, 2, 'draft');
        $this->version($course, 3, 'published');

        $row = RelCourse::query()
            ->withCount(['versions' => fn ($query) => $query->where('status', 'published')])
            ->first();

        $this->assertSame(2, (int) $row->versions_count);
    }

    public function test_with_count_can_be_aliased(): void
    {
        $cat = (int) DB::table('rel_categories')->insertGetId(['name' => 'Food']);
        $course = $this->course($cat, 'Mixed');
        $this->version($course, 1, 'published');

        $row = RelCourse::query()
            ->withCount(['versions as live_count' => fn ($query) => $query->where('status', 'published')])
            ->first();

        $this->assertSame(1, (int) $row->live_count);
    }

    public function test_a_row_with_no_related_records_counts_zero(): void
    {
        $cat = (int) DB::table('rel_categories')->insertGetId(['name' => 'Food']);
        $this->course($cat, 'Lonely');

        $this->assertSame(0, (int) RelCourse::query()->withCount('versions')->first()->versions_count);
    }

    public function test_counting_two_relations_at_once(): void
    {
        $cat = (int) DB::table('rel_categories')->insertGetId(['name' => 'Food']);
        $a = $this->course($cat, 'A');
        $this->version($a, 1, 'published');
        $this->course($cat, 'B');

        $row = RelCategory::query()
            ->withCount(['courses', 'courses as live_courses_count' => fn ($q) => $q->whereHas('versions')])
            ->first();

        $this->assertSame(2, (int) $row->courses_count);
        $this->assertSame(1, (int) $row->live_courses_count);
    }
}

// ─── Fixtures ─────────────────────────────────────────────

class RelCategory extends Model
{
    protected string $table = 'rel_categories';
    protected array $fillable = ['name'];

    public function courses(): \Nitro\Database\Model\Relations\HasMany
    {
        return $this->hasMany(RelCourse::class);
    }
}

class RelCourse extends Model
{
    protected string $table = 'rel_courses';
    protected array $fillable = ['rel_category_id', 'title', 'status'];

    public function category(): \Nitro\Database\Model\Relations\BelongsTo
    {
        return $this->belongsTo(RelCategory::class);
    }

    public function versions(): \Nitro\Database\Model\Relations\HasMany
    {
        return $this->hasMany(RelVersion::class);
    }
}

class RelVersion extends Model
{
    protected string $table = 'rel_versions';
    protected array $fillable = ['rel_course_id', 'version', 'status'];
}
