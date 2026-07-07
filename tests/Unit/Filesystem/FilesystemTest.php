<?php

namespace Tests\Unit\Filesystem;

use Nitro\Filesystem\Contracts\Filesystem;
use Nitro\Filesystem\FilesystemManager;
use Nitro\Filesystem\LocalFilesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FilesystemTest extends TestCase
{
    private string $root;
    private LocalFilesystem $disk;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/nitro-fs-' . uniqid();
        mkdir($this->root);
        $this->disk = new LocalFilesystem($this->root, ['url' => 'https://cdn.test/files']);
    }

    protected function tearDown(): void
    {
        $this->disk->deleteDirectory('');
        @rmdir($this->root);
    }

    public function test_put_get_exists_missing(): void
    {
        $this->assertTrue($this->disk->missing('a.txt'));

        $this->disk->put('a.txt', 'hello');

        $this->assertTrue($this->disk->exists('a.txt'));
        $this->assertSame('hello', $this->disk->get('a.txt'));
        $this->assertNull($this->disk->get('nope.txt'));
    }

    public function test_put_creates_nested_directories(): void
    {
        $this->disk->put('deep/nested/dir/file.txt', 'x');
        $this->assertSame('x', $this->disk->get('deep/nested/dir/file.txt'));
    }

    public function test_prepend_append(): void
    {
        $this->disk->put('log.txt', 'B');
        $this->disk->prepend('log.txt', 'A');
        $this->disk->append('log.txt', 'C');
        $this->assertSame('ABC', $this->disk->get('log.txt'));
    }

    public function test_delete_copy_move(): void
    {
        $this->disk->put('one.txt', '1');
        $this->disk->copy('one.txt', 'sub/two.txt');
        $this->assertSame('1', $this->disk->get('sub/two.txt'));

        $this->disk->move('sub/two.txt', 'three.txt');
        $this->assertTrue($this->disk->missing('sub/two.txt'));
        $this->assertSame('1', $this->disk->get('three.txt'));

        $this->disk->delete(['one.txt', 'three.txt']);
        $this->assertTrue($this->disk->missing('one.txt'));
        $this->assertTrue($this->disk->missing('three.txt'));
    }

    public function test_size_and_last_modified(): void
    {
        $this->disk->put('s.txt', 'abcd');
        $this->assertSame(4, $this->disk->size('s.txt'));
        $this->assertIsInt($this->disk->lastModified('s.txt'));
        $this->assertNull($this->disk->size('missing.txt'));
    }

    public function test_files_directories_and_all_files(): void
    {
        $this->disk->put('root.txt', 'x');
        $this->disk->put('dir/child.txt', 'y');
        $this->disk->makeDirectory('empty');

        $this->assertSame(['root.txt'], $this->disk->files());
        $this->assertContains('dir', $this->disk->directories());
        $this->assertContains('empty', $this->disk->directories());
        $this->assertEqualsCanonicalizing(['dir/child.txt', 'root.txt'], $this->disk->allFiles());
    }

    public function test_delete_directory(): void
    {
        $this->disk->put('trash/a.txt', 'x');
        $this->disk->put('trash/sub/b.txt', 'y');

        $this->assertTrue($this->disk->deleteDirectory('trash'));
        $this->assertFalse($this->disk->exists('trash'));
    }

    public function test_put_file_as_stores_uploaded_content(): void
    {
        $src = $this->root . '/source.bin';
        file_put_contents($src, 'payload');

        $stored = $this->disk->putFileAs('uploads', $src, 'saved.bin');

        $this->assertSame('uploads/saved.bin', $stored);
        $this->assertSame('payload', $this->disk->get($stored));
        @unlink($src);
    }

    public function test_url(): void
    {
        $this->assertSame('https://cdn.test/files/avatars/1.png', $this->disk->url('avatars/1.png'));
    }

    public function test_path_traversal_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->disk->get('../../../etc/passwd');
    }

    public function test_manager_resolves_disks_and_proxies_default(): void
    {
        $manager = new FilesystemManager([
            'default' => 'local',
            'disks' => [
                'local'  => ['driver' => 'local', 'root' => $this->root],
                'public' => ['driver' => 'local', 'root' => $this->root . '/public', 'url' => 'https://x/s'],
            ],
        ]);

        $this->assertInstanceOf(Filesystem::class, $manager->disk());
        $this->assertInstanceOf(Filesystem::class, $manager->disk('public'));

        // Proxy to default disk.
        $manager->put('proxied.txt', 'via manager');
        $this->assertSame('via manager', $manager->get('proxied.txt'));
    }

    public function test_manager_rejects_unknown_disk(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FilesystemManager(['disks' => []]))->disk('ghost');
    }
}
