<?php

namespace Tests\Unit\Filesystem;

use Nitro\Filesystem\Filesystem;
use Nitro\Filesystem\FilesystemManager;
use Nitro\Filesystem\LocalFilesystem;
use Nitro\Filesystem\ScopedFilesystem;
use PHPUnit\Framework\TestCase;

/**
 * A disk that is a subtree of another disk.
 *
 * What a per-tenant or per-user disk is: code written against a whole disk is
 * handed one of these and cannot reach anything outside its subtree, without
 * knowing it is in one. The containment is a property of the disk rather than
 * something every call site has to remember to prefix.
 */
class ScopedDiskTest extends TestCase
{
    private string $root;

    private FilesystemManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/nitro-scoped-' . getmypid() . '-' . uniqid();

        @mkdir($this->root, 0777, true);

        $this->manager = new FilesystemManager([
            'default' => 'main',
            'disks' => [
                'main' => ['driver' => 'local', 'root' => $this->root, 'url' => 'https://files.example.com'],
                'tenant' => ['driver' => 'scoped', 'disk' => 'main', 'prefix' => 'tenants/17'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->root);

        parent::tearDown();
    }

    private function scoped(): ScopedFilesystem
    {
        return $this->manager->disk('tenant');
    }

    public function test_a_write_lands_under_the_prefix(): void
    {
        $this->scoped()->put('logo.png', 'pretend png');

        $this->assertSame('pretend png', $this->manager->disk('main')->get('tenants/17/logo.png'));
    }

    /** The prefix is invisible from inside. */
    public function test_a_read_does_not_mention_the_prefix(): void
    {
        $this->manager->disk('main')->put('tenants/17/report.csv', 'a,b,c');

        $this->assertSame('a,b,c', $this->scoped()->get('report.csv'));
        $this->assertTrue($this->scoped()->exists('report.csv'));
    }

    public function test_listings_come_back_without_the_prefix(): void
    {
        $scoped = $this->scoped();

        $scoped->put('one.txt', 'x');
        $scoped->put('nested/two.txt', 'x');

        $this->assertSame(['one.txt'], $scoped->files());
        $this->assertSame(['nested', 'nested/two.txt', 'one.txt'], $this->sorted($scoped->allFiles(), $scoped->allDirectories()));
        $this->assertSame(['nested'], $scoped->directories());
    }

    /** @return array<int, string> */
    private function sorted(array ...$lists): array
    {
        $all = array_merge(...$lists);

        sort($all);

        return $all;
    }

    /**
     * Another tenant's files are not visible, which is the whole point.
     */
    public function test_it_cannot_see_a_sibling_subtree(): void
    {
        $this->manager->disk('main')->put('tenants/18/secret.txt', 'not yours');

        $this->assertFalse($this->scoped()->exists('secret.txt'));
        $this->assertSame([], $this->scoped()->files());
    }

    /**
     * Climbing out with '..' is dropped rather than resolved: resolving it is
     * precisely the escape this exists to prevent.
     */
    public function test_a_path_cannot_climb_out_of_the_subtree(): void
    {
        $this->manager->disk('main')->put('tenants/18/secret.txt', 'not yours');

        $this->assertNull($this->scoped()->get('../18/secret.txt'));
        $this->assertFalse($this->scoped()->exists('../../tenants/18/secret.txt'));

        // And a write meant to escape stays inside.
        $this->scoped()->put('../escaped.txt', 'contained');

        $this->assertSame('contained', $this->manager->disk('main')->get('tenants/17/escaped.txt'));
        $this->assertFalse($this->manager->disk('main')->exists('escaped.txt'));
    }

    public function test_deleting_only_reaches_inside(): void
    {
        $this->manager->disk('main')->put('tenants/18/theirs.txt', 'x');
        $this->scoped()->put('mine.txt', 'x');

        $this->scoped()->delete('../18/theirs.txt');

        $this->assertTrue($this->manager->disk('main')->exists('tenants/18/theirs.txt'));
    }

    public function test_a_url_carries_the_prefix_because_the_object_is_there(): void
    {
        $this->assertSame(
            'https://files.example.com/tenants/17/logo.png',
            $this->scoped()->url('logo.png'),
        );
    }

    public function test_it_inherits_what_the_disk_underneath_can_do(): void
    {
        $scoped = $this->scoped();

        // A local disk cannot sign, so neither can a subtree of one.
        $this->assertFalse($scoped->providesTemporaryUrls());

        $scoped->buildTemporaryUrlsUsing(
            static fn (string $path, int $seconds): string => "https://cdn.example.com/{$path}"
        );

        $this->assertTrue($scoped->providesTemporaryUrls());
        $this->assertSame('https://cdn.example.com/logo.png', $scoped->temporaryUrl('logo.png'));
    }

    public function test_copying_between_a_scope_and_its_parent_works(): void
    {
        $this->scoped()->put('export.csv', 'a,b');

        $this->assertTrue($this->scoped()->copyToDisk('export.csv', $this->manager->disk('main'), 'shared/export.csv'));
        $this->assertSame('a,b', $this->manager->disk('main')->get('shared/export.csv'));
    }

    public function test_a_scope_can_be_built_on_demand(): void
    {
        $disk = $this->manager->build([
            'driver' => 'scoped',
            'disk' => 'main',
            'prefix' => 'tenants/99',
        ]);

        $disk->put('adhoc.txt', 'x');

        $this->assertTrue($this->manager->disk('main')->exists('tenants/99/adhoc.txt'));
    }

    public function test_a_scope_over_an_inline_disk_configuration(): void
    {
        $disk = $this->manager->build([
            'driver' => 'scoped',
            'disk' => ['driver' => 'local', 'root' => $this->root],
            'prefix' => 'inline',
        ]);

        $disk->put('a.txt', 'x');

        $this->assertTrue($this->manager->disk('main')->exists('inline/a.txt'));
    }

    public function test_a_scope_must_name_what_it_is_a_subtree_of(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[disk]');

        $this->manager->build(['driver' => 'scoped', 'prefix' => 'x']);
    }

    public function test_the_disk_underneath_is_reachable(): void
    {
        $this->assertInstanceOf(LocalFilesystem::class, $this->scoped()->getDisk());
    }

    /** An empty prefix is the disk itself, not a directory called ''. */
    public function test_an_empty_prefix_is_a_pass_through(): void
    {
        $disk = new ScopedFilesystem($this->manager->disk('main'), '');

        $disk->put('top.txt', 'x');

        $this->assertSame(['top.txt'], $disk->files());
        $this->assertTrue($this->manager->disk('main')->exists('top.txt'));
    }
}
