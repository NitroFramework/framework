<?php

namespace Tests\Unit\Filesystem;

use Nitro\Filesystem\Contracts\Filesystem as FilesystemContract;
use Nitro\Filesystem\Filesystem;
use Nitro\Filesystem\FilesystemManager;
use Nitro\Filesystem\LocalFilesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What a disk can be asked beyond reading and writing a whole file.
 *
 * Streaming, cross-disk moves, visibility, signed URLs and the existence of a
 * directory as distinct from a file were all absent from the contract, so an
 * application needing any of them had to reach for the concrete driver and
 * hope it had the method — or, in the case of a signed URL, call something
 * that existed on the S3 driver and fataled on the local one.
 */
class DiskCapabilityTest extends TestCase
{
    private string $root;

    private FilesystemManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/nitro-disk-' . getmypid() . '-' . uniqid();

        @mkdir($this->root . '/main', 0777, true);
        @mkdir($this->root . '/other', 0777, true);

        $this->manager = new FilesystemManager([
            'default' => 'main',
            'cloud' => 'other',
            'disks' => [
                'main' => ['driver' => 'local', 'root' => $this->root . '/main'],
                'other' => ['driver' => 'local', 'root' => $this->root . '/other'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->root);

        parent::tearDown();
    }

    private function disk(string $name = 'main'): FilesystemContract
    {
        return $this->manager->disk($name);
    }

    // ─── A file is not a directory ────────────────────────

    /**
     * exists() answers for both, which is not enough to act on: writing to a
     * path that holds a directory is a different problem from writing to a
     * path that holds a file.
     */
    public function test_a_file_and_a_directory_are_told_apart(): void
    {
        $disk = $this->disk();

        $disk->put('a-file.txt', 'contents');
        $disk->makeDirectory('a-directory');

        $this->assertTrue($disk->exists('a-file.txt'));
        $this->assertTrue($disk->exists('a-directory'));

        $this->assertTrue($disk->fileExists('a-file.txt'));
        $this->assertFalse($disk->fileExists('a-directory'));

        $this->assertTrue($disk->directoryExists('a-directory'));
        $this->assertFalse($disk->directoryExists('a-file.txt'));

        $this->assertTrue($disk->fileMissing('a-directory'));
        $this->assertTrue($disk->directoryMissing('a-file.txt'));
    }

    public function test_directories_can_be_listed_recursively(): void
    {
        $disk = $this->disk();

        $disk->put('top/middle/bottom/deep.txt', 'x');

        $this->assertSame(['top'], $disk->directories());
        $this->assertSame(['top', 'top/middle', 'top/middle/bottom'], $disk->allDirectories());
    }

    // ─── Streams ──────────────────────────────────────────

    /** For a file too large to hold in memory. */
    public function test_a_file_can_be_read_and_written_as_a_stream(): void
    {
        $disk = $this->disk();

        $disk->put('source.txt', 'streamed contents');

        $stream = $disk->readStream('source.txt');

        $this->assertIsResource($stream);
        $this->assertTrue($disk->writeStream('target.txt', $stream));

        fclose($stream);

        $this->assertSame('streamed contents', $disk->get('target.txt'));
    }

    public function test_reading_a_missing_file_as_a_stream_gives_null(): void
    {
        $this->assertNull($this->disk()->readStream('absent.txt'));
    }

    // ─── Between disks ────────────────────────────────────

    /**
     * Streamed, so moving an upload from a scratch disk to a bucket does not
     * size the request by the file.
     */
    public function test_a_file_can_be_copied_to_another_disk(): void
    {
        $this->disk('main')->put('report.csv', 'a,b,c');

        $this->assertTrue($this->disk('main')->copyToDisk('report.csv', $this->disk('other')));

        $this->assertSame('a,b,c', $this->disk('other')->get('report.csv'));
        $this->assertTrue($this->disk('main')->exists('report.csv'), 'a copy leaves the original');
    }

    public function test_copying_to_another_disk_can_rename_on_the_way(): void
    {
        $this->disk('main')->put('report.csv', 'a,b,c');
        $this->disk('main')->copyToDisk('report.csv', $this->disk('other'), 'archive/2026.csv');

        $this->assertSame('a,b,c', $this->disk('other')->get('archive/2026.csv'));
    }

    public function test_a_file_can_be_moved_to_another_disk(): void
    {
        $this->disk('main')->put('temp.bin', 'payload');

        $this->assertTrue($this->disk('main')->moveToDisk('temp.bin', $this->disk('other')));

        $this->assertSame('payload', $this->disk('other')->get('temp.bin'));
        $this->assertFalse($this->disk('main')->exists('temp.bin'), 'a move does not leave the original');
    }

    public function test_copying_a_file_that_is_not_there_is_false(): void
    {
        $this->assertFalse($this->disk('main')->copyToDisk('absent.txt', $this->disk('other')));
    }

    // ─── Metadata ─────────────────────────────────────────

    public function test_the_media_type_is_read_from_the_contents(): void
    {
        $disk = $this->disk();

        $disk->put('doc.txt', 'plain words');

        $this->assertSame('text/plain', $disk->mimeType('doc.txt'));
        $this->assertNull($disk->mimeType('absent.txt'));
    }

    public function test_a_checksum_is_available(): void
    {
        $disk = $this->disk();

        $disk->put('a.bin', 'same');
        $disk->put('b.bin', 'same');
        $disk->put('c.bin', 'different');

        $this->assertSame($disk->checksum('a.bin'), $disk->checksum('b.bin'));
        $this->assertNotSame($disk->checksum('a.bin'), $disk->checksum('c.bin'));
        $this->assertSame(hash('sha256', 'same'), $disk->checksum('a.bin', ['checksum_algo' => 'sha256']));
        $this->assertNull($disk->checksum('absent.bin'));
    }

    public function test_json_is_decoded_and_a_bad_file_gives_null(): void
    {
        $disk = $this->disk();

        $disk->put('good.json', '{"a":1}');
        $disk->put('bad.json', 'not json at all');

        $this->assertSame(['a' => 1], $disk->json('good.json'));
        $this->assertNull($disk->json('bad.json'));
        $this->assertNull($disk->json('absent.json'));
    }

    // ─── Visibility ───────────────────────────────────────

    /**
     * Read back off the permission bits, which is only meaningful where the
     * platform has them — Windows expresses access through ACLs and ignores
     * chmod beyond the read-only flag, so a local disk there cannot answer.
     */
    public function test_visibility_can_be_read_and_changed(): void
    {
        $disk = $this->disk();

        $this->assertNull($disk->getVisibility('absent.txt'));

        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permission bits do not carry visibility on Windows.');
        }

        $disk->put('secret.txt', 'x', ['visibility' => 'private']);

        $this->assertSame('private', $disk->getVisibility('secret.txt'));

        $disk->setVisibility('secret.txt', 'public');

        $this->assertSame('public', $disk->getVisibility('secret.txt'));
    }

    // ─── Signed URLs ──────────────────────────────────────

    /**
     * A local directory cannot sign anything, and saying so beats handing back
     * a URL that is not actually time-limited. The S3 driver had this method
     * and the local one did not, so Storage::temporaryUrl() on the default
     * disk fataled rather than explaining itself.
     */
    public function test_a_local_disk_says_it_cannot_sign(): void
    {
        $disk = $this->disk();

        $this->assertFalse($disk->providesTemporaryUrls());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('providesTemporaryUrls');

        $disk->temporaryUrl('secret.txt');
    }

    /** An application serving through a CDN signs for the CDN's host. */
    public function test_signing_can_be_taken_over_by_the_application(): void
    {
        $disk = $this->disk();

        $disk->buildTemporaryUrlsUsing(
            static fn (string $path, int $seconds): string => "https://cdn.example.com/{$path}?expires={$seconds}"
        );

        $this->assertTrue($disk->providesTemporaryUrls());
        $this->assertSame('https://cdn.example.com/a.png?expires=60', $disk->temporaryUrl('a.png', 60));
    }

    // ─── Responses ────────────────────────────────────────

    public function test_a_file_can_be_served_as_a_download(): void
    {
        $disk = $this->disk();

        $disk->put('invoice.pdf', 'pretend pdf');

        $response = $disk->download('invoice.pdf');

        $this->assertSame('attachment', $response->disposition());
        $this->assertSame('invoice.pdf', $response->filename());
        $this->assertSame('pretend pdf', file_get_contents($response->path()));
    }

    public function test_a_file_can_be_served_inline(): void
    {
        $disk = $this->disk();

        $disk->put('photo.png', 'pretend png');

        $this->assertSame('inline', $disk->response('photo.png')->disposition());
    }

    /** A name for the download that is not the name it is stored under. */
    public function test_a_download_can_be_renamed(): void
    {
        $disk = $this->disk();

        $disk->put('a7f3c9.pdf', 'pretend pdf');

        $this->assertSame('Invoice 2026.pdf', $disk->download('a7f3c9.pdf', 'Invoice 2026.pdf')->filename());
    }

    // ─── The manager ──────────────────────────────────────

    /**
     * There was no way to add a driver at all: the driver list was a match
     * statement with nothing registering into it.
     */
    public function test_a_driver_can_be_registered_from_outside(): void
    {
        $this->manager->extend('memory', function (array $config, string $name): FilesystemContract {
            return new LocalFilesystem($this->root . '/main', $config + ['name' => $name]);
        });

        $disk = $this->manager->build(['driver' => 'memory', 'marker' => 'used']);

        $this->assertSame('used', $disk->getConfig()['marker']);
    }

    /** A registered driver replaces a built-in one of the same name. */
    public function test_a_registered_driver_wins_over_a_builtin(): void
    {
        $called = false;

        $this->manager->extend('local', function (array $config) use (&$called): FilesystemContract {
            $called = true;

            return new LocalFilesystem($this->root . '/main', $config);
        });

        $this->manager->forgetDisk('main');
        $this->manager->disk('main');

        $this->assertTrue($called);
    }

    /** For a root named by the request, that there is no reason to configure. */
    public function test_a_disk_can_be_built_on_demand(): void
    {
        $disk = $this->manager->build($this->root . '/other');

        $disk->put('adhoc.txt', 'built on the spot');

        $this->assertSame('built on the spot', $this->disk('other')->get('adhoc.txt'));
    }

    public function test_a_disk_can_be_put_in_place_for_a_test(): void
    {
        $swapped = new LocalFilesystem($this->root . '/other');

        $this->manager->set('main', $swapped);

        $this->manager->disk('main')->put('landed.txt', 'x');

        $this->assertTrue($this->disk('other')->exists('landed.txt'));
    }

    public function test_forgetting_a_disk_builds_it_again(): void
    {
        $first = $this->manager->disk('main');

        $this->assertSame($first, $this->manager->disk('main'), 'resolved disks are cached');

        $this->manager->forgetDisk('main');

        $this->assertNotSame($first, $this->manager->disk('main'));
    }

    public function test_purge_forgets_every_disk(): void
    {
        $main = $this->manager->disk('main');
        $other = $this->manager->disk('other');

        $this->manager->purge();

        $this->assertNotSame($main, $this->manager->disk('main'));
        $this->assertNotSame($other, $this->manager->disk('other'));
    }

    public function test_the_cloud_disk_is_the_configured_one(): void
    {
        $this->manager->cloud()->put('in-the-cloud.txt', 'x');

        $this->assertTrue($this->disk('other')->exists('in-the-cloud.txt'));
    }

    public function test_an_unknown_driver_says_so(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nonsense');

        $this->manager->build(['driver' => 'nonsense']);
    }

    // ─── Assertions a test makes ──────────────────────────

    public function test_a_disk_can_assert_on_what_it_holds(): void
    {
        $disk = $this->disk();

        $disk->put('kept.txt', 'the contents');
        $disk->makeDirectory('nothing-here');

        $disk->assertExists('kept.txt')
            ->assertExists('kept.txt', 'the contents')
            ->assertMissing('never-written.txt')
            ->assertMissing(['nor-this.txt', 'or-this.txt'])
            ->assertDirectoryEmpty('nothing-here');

        $this->assertTrue(true, 'the assertions above are the subject');
    }

    public function test_asserting_a_missing_file_exists_fails(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);

        $this->disk()->assertExists('never-written.txt');
    }

    // ─── Conditionable ────────────────────────────────────

    public function test_a_disk_call_can_be_made_conditionally(): void
    {
        $disk = $this->disk();

        $disk->when(true, static fn (FilesystemContract $d) => $d->put('ran.txt', 'x'));
        $disk->when(false, static fn (FilesystemContract $d) => $d->put('skipped.txt', 'x'));

        $this->assertTrue($disk->exists('ran.txt'));
        $this->assertFalse($disk->exists('skipped.txt'));
    }
}
