<?php

namespace Tests\Unit\Livewire;

use Nitro\Livewire\TemporaryUploadedFile;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Security tests: an uploaded file must report its REAL type/size (from the
 * bytes on disk), never the client's claim, and a store directory must not be
 * able to escape storage/app via traversal.
 */
class TemporaryUploadedFileSecurityTest extends TestCase
{
    public function test_get_mime_type_reads_content_not_the_client_claim(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, "this is plain text, definitely not a PNG image");

        // Client LIES: claims image/png (and a huge size).
        $file = new class($tmp, ['type' => 'image/png', 'size' => 9_999_999]) extends TemporaryUploadedFile {
            public string $real = '';
            public function getRealPath(): string { return $this->real; }
        };
        $file->real = $tmp;

        // getMimeType() must reflect the real bytes, not the claim.
        $this->assertNotSame('image/png', $file->getMimeType());
        // The claim is available only via the explicitly-untrusted accessor.
        $this->assertSame('image/png', $file->getClientMimeType());
        // Real size, not the claimed 9,999,999.
        $this->assertSame(strlen("this is plain text, definitely not a PNG image"), $file->getSize());

        @unlink($tmp);
    }

    public function test_store_directory_cannot_traverse_out_of_storage(): void
    {
        $file = new TemporaryUploadedFile('x.png', ['type' => 'image/png']);
        $m = new ReflectionMethod(TemporaryUploadedFile::class, 'sanitizeDirectory');

        $this->assertSame('etc', $m->invoke($file, '../../etc'));
        $this->assertSame('a/b', $m->invoke($file, 'a/../../b'));
        $this->assertSame('uploads/avatars', $m->invoke($file, '/uploads/avatars/'));
        $this->assertSame('windows/system32', $m->invoke($file, 'C:\\windows\\system32'));
        $this->assertSame('', $m->invoke($file, '../../../'));
    }

    public function test_traversal_filename_is_basenamed(): void
    {
        // A token/filename like ../../etc/passwd must be reduced to its basename
        // so getRealPath() can't point outside livewire-tmp.
        $file = new TemporaryUploadedFile('../../etc/passwd', ['type' => 'x']);
        $this->assertSame('passwd', $file->getFilename());
    }
}
