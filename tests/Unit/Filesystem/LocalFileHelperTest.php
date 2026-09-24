<?php

namespace Tests\Unit\Filesystem;

use Nitro\Filesystem\Exceptions\FileNotFoundException;
use Nitro\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * The local filesystem, addressed by real paths.
 *
 * Distinct from a storage disk, whose paths are relative to a configured root.
 * The File facade documented this surface while resolving the disk manager, so
 * File::get('/some/absolute/path') went looking under the disk's root and
 * returned nothing — which is what these tests exist to stop recurring.
 */
class LocalFileHelperTest extends TestCase
{
    private string $root;

    private Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/nitro-local-fs-' . getmypid() . '-' . uniqid();
        $this->files = new Filesystem();

        @mkdir($this->root . '/nested/deeper', 0777, true);
        @mkdir($this->root . '/empty', 0777, true);

        file_put_contents($this->root . '/one.txt', "first\nsecond\nthird");
        file_put_contents($this->root . '/data.json', '{"a":1,"b":[2,3]}');
        file_put_contents($this->root . '/.dotfile', 'hidden');
        file_put_contents($this->root . '/nested/two.txt', 'nested');
        file_put_contents($this->root . '/nested/deeper/three.txt', 'deep');
        file_put_contents($this->root . '/returns.php', '<?php return ["key" => $injected ?? "default"];');
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->root);

        parent::tearDown();
    }

    /** Relative to the fixture root, with forward slashes, for readable assertions. */
    private function relative(array $paths): array
    {
        return array_map(function (string|SplFileInfo $path): string {
            $path = $path instanceof SplFileInfo ? $path->getPathname() : $path;

            return trim(str_replace([str_replace('\\', '/', $this->root), '\\'], ['', '/'], str_replace('\\', '/', $path)), '/');
        }, $paths);
    }

    // ─── Reading ──────────────────────────────────────────

    public function test_it_reads_a_file_by_its_real_path(): void
    {
        $this->assertTrue($this->files->exists($this->root . '/one.txt'));
        $this->assertSame("first\nsecond\nthird", $this->files->get($this->root . '/one.txt'));
    }

    /**
     * Reading has no useful falsy answer — an empty string is a legitimate
     * file — so a missing path throws rather than making every caller check.
     */
    public function test_reading_a_missing_file_throws_and_names_the_path(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage($this->root . '/absent.txt');

        $this->files->get($this->root . '/absent.txt');
    }

    public function test_reading_a_directory_throws_too(): void
    {
        $this->expectException(FileNotFoundException::class);

        $this->files->get($this->root . '/nested');
    }

    public function test_json_is_decoded(): void
    {
        $this->assertSame(['a' => 1, 'b' => [2, 3]], $this->files->json($this->root . '/data.json'));
    }

    /** Lazy, so a log larger than memory can still be walked. */
    public function test_lines_are_yielded_one_at_a_time(): void
    {
        $lines = $this->files->lines($this->root . '/one.txt');

        $this->assertInstanceOf(\Generator::class, $lines);
        $this->assertSame(['first', 'second', 'third'], iterator_to_array($lines, false));
    }

    public function test_a_php_file_can_be_required_for_its_return_value(): void
    {
        $this->assertSame(['key' => 'default'], $this->files->getRequire($this->root . '/returns.php'));
    }

    public function test_data_can_be_passed_into_a_required_file(): void
    {
        $this->assertSame(
            ['key' => 'passed in'],
            $this->files->getRequire($this->root . '/returns.php', ['injected' => 'passed in']),
        );
    }

    public function test_two_files_are_compared_by_hash(): void
    {
        $this->files->put($this->root . '/a.bin', 'same bytes');
        $this->files->put($this->root . '/b.bin', 'same bytes');
        $this->files->put($this->root . '/c.bin', 'other bytes');

        $this->assertTrue($this->files->hasSameHash($this->root . '/a.bin', $this->root . '/b.bin'));
        $this->assertFalse($this->files->hasSameHash($this->root . '/a.bin', $this->root . '/c.bin'));
        $this->assertFalse($this->files->hasSameHash($this->root . '/absent', $this->root . '/b.bin'));
    }

    // ─── Writing ──────────────────────────────────────────

    public function test_put_writes_and_reports_the_bytes(): void
    {
        $this->assertSame(11, $this->files->put($this->root . '/w.txt', 'eleven char'));
        $this->assertSame('eleven char', $this->files->get($this->root . '/w.txt'));
    }

    public function test_prepend_creates_the_file_when_it_is_absent(): void
    {
        $this->files->prepend($this->root . '/p.txt', 'only this');

        $this->assertSame('only this', $this->files->get($this->root . '/p.txt'));
    }

    public function test_append_and_prepend_around_existing_contents(): void
    {
        $this->files->put($this->root . '/pa.txt', 'body');
        $this->files->prepend($this->root . '/pa.txt', 'head-');
        $this->files->append($this->root . '/pa.txt', '-tail');

        $this->assertSame('head-body-tail', $this->files->get($this->root . '/pa.txt'));
    }

    /**
     * A reader must never see a half-written file, so the write goes to a
     * temporary file in the same directory and is renamed over the target.
     */
    public function test_replace_leaves_no_half_written_state(): void
    {
        $this->files->put($this->root . '/r.txt', 'before');
        $this->files->replace($this->root . '/r.txt', 'after');

        $this->assertSame('after', $this->files->get($this->root . '/r.txt'));

        // The temporary file it wrote through must not be left behind.
        $this->assertSame(
            ['data.json', 'one.txt', 'r.txt', 'returns.php'],
            $this->relative($this->files->files($this->root)),
        );
    }

    public function test_replace_creates_a_file_that_was_not_there(): void
    {
        $this->files->replace($this->root . '/fresh.txt', 'new contents');

        $this->assertSame('new contents', $this->files->get($this->root . '/fresh.txt'));
    }

    public function test_a_string_can_be_replaced_throughout_a_file(): void
    {
        $this->files->put($this->root . '/s.txt', 'hello NAME, goodbye NAME');
        $this->files->replaceInFile('NAME', 'Ada', $this->root . '/s.txt');

        $this->assertSame('hello Ada, goodbye Ada', $this->files->get($this->root . '/s.txt'));
    }

    /** Every path is attempted, and the result says whether all of them went. */
    public function test_deleting_several_reports_a_partial_failure(): void
    {
        $this->files->put($this->root . '/d1.txt', 'x');

        $this->assertFalse($this->files->delete([$this->root . '/d1.txt', $this->root . '/absent.txt']));
        $this->assertFalse($this->files->exists($this->root . '/d1.txt'), 'the one that existed should still go');
    }

    // ─── Describing ───────────────────────────────────────

    public function test_it_takes_a_path_apart(): void
    {
        $path = $this->root . '/nested/two.txt';

        $this->assertSame('two', $this->files->name($path));
        $this->assertSame('two.txt', $this->files->basename($path));
        $this->assertSame('txt', $this->files->extension($path));
        $this->assertSame(str_replace('\\', '/', $this->root . '/nested'), str_replace('\\', '/', $this->files->dirname($path)));
    }

    /** From the contents, for an upload whose name is whatever was sent. */
    public function test_the_extension_can_be_guessed_from_the_contents(): void
    {
        $this->files->put($this->root . '/mislabelled.dat', '{"json":true}');

        $this->assertSame('json', $this->files->guessExtension($this->root . '/mislabelled.dat'));
    }

    public function test_it_distinguishes_files_from_directories(): void
    {
        $this->assertTrue($this->files->isFile($this->root . '/one.txt'));
        $this->assertFalse($this->files->isFile($this->root . '/nested'));
        $this->assertTrue($this->files->isDirectory($this->root . '/nested'));
        $this->assertFalse($this->files->isDirectory($this->root . '/one.txt'));
    }

    public function test_an_empty_directory_is_recognised(): void
    {
        $this->assertTrue($this->files->isEmptyDirectory($this->root . '/empty'));
        $this->assertFalse($this->files->isEmptyDirectory($this->root . '/nested'));
        $this->assertFalse($this->files->isEmptyDirectory($this->root . '/does-not-exist'));
    }

    // ─── Listing ──────────────────────────────────────────

    /** Dotfiles are left out unless asked for, and the order is stable. */
    public function test_files_skips_dotfiles_by_default(): void
    {
        $this->assertSame(
            ['data.json', 'one.txt', 'returns.php'],
            $this->relative($this->files->files($this->root)),
        );

        $this->assertContains('.dotfile', $this->relative($this->files->files($this->root, hidden: true)));
    }

    public function test_files_does_not_descend_unless_asked(): void
    {
        $this->assertNotContains('nested/two.txt', $this->relative($this->files->files($this->root)));
        $this->assertContains('nested/two.txt', $this->relative($this->files->allFiles($this->root)));
        $this->assertContains('nested/deeper/three.txt', $this->relative($this->files->allFiles($this->root)));
    }

    public function test_it_returns_file_info_objects(): void
    {
        $found = $this->files->files($this->root);

        $this->assertNotEmpty($found);
        $this->assertContainsOnlyInstancesOf(SplFileInfo::class, $found);
    }

    public function test_directories_are_listed_shallow_or_deep(): void
    {
        $this->assertSame(['empty', 'nested'], $this->relative($this->files->directories($this->root)));
        $this->assertSame(
            ['empty', 'nested', 'nested/deeper'],
            $this->relative($this->files->allDirectories($this->root)),
        );
    }

    // ─── Directories ──────────────────────────────────────

    public function test_a_directory_tree_can_be_made_in_one_call(): void
    {
        $this->assertTrue($this->files->makeDirectory($this->root . '/a/b/c', 0755, recursive: true));
        $this->assertTrue($this->files->isDirectory($this->root . '/a/b/c'));
    }

    public function test_ensuring_a_directory_exists_is_safe_to_repeat(): void
    {
        $this->assertTrue($this->files->ensureDirectoryExists($this->root . '/ensured'));
        $this->assertTrue($this->files->ensureDirectoryExists($this->root . '/ensured'));
        $this->assertTrue($this->files->isDirectory($this->root . '/ensured'));
    }

    public function test_a_directory_is_copied_with_everything_under_it(): void
    {
        $this->assertTrue($this->files->copyDirectory($this->root . '/nested', $this->root . '/copy'));
        $this->assertSame('nested', $this->files->get($this->root . '/copy/two.txt'));
        $this->assertSame('deep', $this->files->get($this->root . '/copy/deeper/three.txt'));
    }

    public function test_copying_a_directory_that_is_not_there_is_false(): void
    {
        $this->assertFalse($this->files->copyDirectory($this->root . '/absent', $this->root . '/dest'));
    }

    public function test_cleaning_keeps_the_directory_and_deleting_does_not(): void
    {
        $this->files->makeDirectory($this->root . '/temp');
        $this->files->put($this->root . '/temp/f.txt', 'x');

        $this->files->cleanDirectory($this->root . '/temp');

        $this->assertTrue($this->files->isDirectory($this->root . '/temp'));
        $this->assertFalse($this->files->exists($this->root . '/temp/f.txt'));

        $this->files->deleteDirectory($this->root . '/temp');

        $this->assertFalse($this->files->isDirectory($this->root . '/temp'));
    }

    public function test_deleteDirectories_removes_children_and_keeps_files(): void
    {
        $this->files->makeDirectory($this->root . '/parent/one', 0755, true);
        $this->files->put($this->root . '/parent/kept.txt', 'x');

        $this->assertTrue($this->files->deleteDirectories($this->root . '/parent'));
        $this->assertTrue($this->files->exists($this->root . '/parent/kept.txt'));
        $this->assertSame([], $this->files->directories($this->root . '/parent'));
    }

    /**
     * Descending into a symlinked directory would delete whatever it points
     * at, which is outside the tree being removed.
     */
    public function test_deleting_a_tree_unlinks_a_symlink_rather_than_following_it(): void
    {
        $this->files->makeDirectory($this->root . '/outside');
        $this->files->put($this->root . '/outside/precious.txt', 'must survive');
        $this->files->makeDirectory($this->root . '/doomed');

        if (! @$this->files->link($this->root . '/outside', $this->root . '/doomed/pointer')) {
            $this->markTestSkipped('this platform would not make the link');
        }

        $this->files->deleteDirectory($this->root . '/doomed');

        $this->assertFalse($this->files->isDirectory($this->root . '/doomed'));
        $this->assertSame('must survive', $this->files->get($this->root . '/outside/precious.txt'));
    }

    // ─── Conditionable ────────────────────────────────────

    public function test_a_call_can_be_made_conditionally(): void
    {
        $this->files->when(true, fn (Filesystem $files) => $files->put($this->root . '/when.txt', 'ran'));
        $this->files->when(false, fn (Filesystem $files) => $files->put($this->root . '/not.txt', 'ran'));

        $this->assertTrue($this->files->exists($this->root . '/when.txt'));
        $this->assertFalse($this->files->exists($this->root . '/not.txt'));
    }
}
