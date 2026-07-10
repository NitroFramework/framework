<?php

namespace Tests\Unit\Database;

use Nitro\Database\Query\Exceptions\QueryNotFoundException;
use Nitro\Database\Query\Queries;
use Nitro\Database\Query\QueryRegistry;
use PHPUnit\Framework\TestCase;

/** A method-based query group with an explicit prefix. */
class StudentQueriesFixture extends Queries
{
    protected string $as = 'students';

    public function all(): string { return 'all-students'; }
    public function withGpa(float $min): string { return "gpa>={$min}"; }
}

/** A group with no $as → prefix defaults to the slug of the class name minus "Queries". */
class TeacherQueries extends Queries
{
    public function active(): string { return 'active-teachers'; }
}

class QueryRegistryTest extends TestCase
{
    public function test_register_and_resolve(): void
    {
        $r = new QueryRegistry();
        $r->register('answer', fn () => 42);

        $this->assertTrue($r->has('answer'));
        $this->assertSame(42, $r->resolve('answer'));
    }

    public function test_resolve_passes_parameters(): void
    {
        $r = new QueryRegistry();
        $r->register('sum', fn (int $a, int $b) => $a + $b);

        $this->assertSame(7, $r->resolve('sum', [3, 4]));
    }

    public function test_register_many_from_a_manifest(): void
    {
        $r = new QueryRegistry();
        $r->registerMany([
            'a' => fn () => 'A',
            'b' => fn () => 'B',
        ]);

        $this->assertSame(['a', 'b'], $r->names());
        $this->assertSame('B', $r->resolve('b'));
    }

    public function test_a_named_query_may_reference_another(): void
    {
        $r = new QueryRegistry();
        $r->register('base', fn () => ['x' => 1]);
        $r->register('derived', fn () => $GLOBALS['__reg']->resolve('base') + ['y' => 2]);
        $GLOBALS['__reg'] = $r;

        $this->assertSame(['x' => 1, 'y' => 2], $r->resolve('derived'));
        unset($GLOBALS['__reg']);
    }

    public function test_unknown_query_throws(): void
    {
        $this->expectException(QueryNotFoundException::class);
        (new QueryRegistry())->resolve('nope');
    }

    // ── Class-based (method) query groups ──────────────────────────────

    public function test_register_group_registers_each_method(): void
    {
        $r = new QueryRegistry();
        $r->registerGroup(new StudentQueriesFixture());

        $this->assertTrue($r->has('students.all'));
        $this->assertTrue($r->has('students.withGpa'));
        $this->assertSame('all-students', $r->resolve('students.all'));
        $this->assertSame('gpa>=3.5', $r->resolve('students.withGpa', [3.5]));
    }

    public function test_group_prefix_defaults_to_class_slug(): void
    {
        $r = new QueryRegistry();
        $r->registerGroup(new TeacherQueries());

        // TeacherQueries → prefix "teacher"
        $this->assertTrue($r->has('teacher.active'));
        $this->assertSame('active-teachers', $r->resolve('teacher.active'));
    }

    public function test_load_from_missing_directory_is_a_no_op(): void
    {
        $r = new QueryRegistry();
        $r->loadFrom(__DIR__ . '/does-not-exist');

        $this->assertSame([], $r->names());
    }

    public function test_load_from_reads_manifest_files(): void
    {
        $dir = sys_get_temp_dir() . '/nitro_queries_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/StudentQueries.php', "<?php\nreturn ['students.all' => fn () => 'all', 'students.top' => fn () => 'top'];\n");

        $r = new QueryRegistry();
        $r->loadFrom($dir);

        $this->assertTrue($r->has('students.all'));
        $this->assertSame('top', $r->resolve('students.top'));

        unlink($dir . '/StudentQueries.php');
        rmdir($dir);
    }
}
