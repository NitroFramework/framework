<?php

namespace Tests\Unit\Session;

use Nitro\Session\Handlers\FileSessionHandler;
use PHPUnit\Framework\TestCase;

class FileSessionHandlerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nitro_sess_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function test_constructor_creates_directory(): void
    {
        new FileSessionHandler($this->dir);
        $this->assertDirectoryExists($this->dir);
    }

    public function test_write_creates_file_and_read_returns_payload(): void
    {
        $h = new FileSessionHandler($this->dir);
        $this->assertTrue($h->write('abc', 'serialized-data'));
        $this->assertFileExists($this->dir . DIRECTORY_SEPARATOR . 'abc');
        $this->assertSame('serialized-data', $h->read('abc'));
    }

    public function test_read_missing_returns_empty_string(): void
    {
        $h = new FileSessionHandler($this->dir);
        $this->assertSame('', $h->read('ghost'));
    }

    public function test_destroy_removes_file(): void
    {
        $h = new FileSessionHandler($this->dir);
        $h->write('abc', 'data');
        $this->assertTrue($h->destroy('abc'));
        $this->assertFileDoesNotExist($this->dir . DIRECTORY_SEPARATOR . 'abc');
    }

    public function test_expired_entry_reads_empty_and_is_cleaned(): void
    {
        // lifetime 0 minutes → anything already written is immediately expired.
        $h = new FileSessionHandler($this->dir, 0);
        $h->write('abc', 'data');
        // Backdate the file so it is strictly older than "now".
        touch($this->dir . DIRECTORY_SEPARATOR . 'abc', time() - 10);
        $this->assertSame('', $h->read('abc'));
        $this->assertFileDoesNotExist($this->dir . DIRECTORY_SEPARATOR . 'abc');
    }

    public function test_gc_removes_old_files(): void
    {
        $h = new FileSessionHandler($this->dir);
        $h->write('old', 'data');
        touch($this->dir . DIRECTORY_SEPARATOR . 'old', time() - 1000);
        $h->write('fresh', 'data');

        $removed = $h->gc(500);
        $this->assertSame(1, $removed);
        $this->assertFileDoesNotExist($this->dir . DIRECTORY_SEPARATOR . 'old');
        $this->assertFileExists($this->dir . DIRECTORY_SEPARATOR . 'fresh');
    }
}
