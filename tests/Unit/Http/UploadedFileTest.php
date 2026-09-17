<?php

namespace Tests\Unit\Http;

use Nitro\Http\FileBag;
use Nitro\Http\MimeTypes;
use Nitro\Http\Request;
use Nitro\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * Upload handling: $_FILES normalization, file()/hasFile(), and the
 * UploadedFile surface.
 */
class UploadedFileTest extends TestCase
{
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->tempFiles = [];
    }

    private function tempFile(string $contents = 'hello', string $suffix = '.txt'): string
    {
        $path = sys_get_temp_dir() . '/nitro_upload_' . bin2hex(random_bytes(6)) . $suffix;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    // ─── Normalization ────────────────────────────────────

    public function test_single_file_normalizes_to_an_uploaded_file(): void
    {
        $path = $this->tempFile();

        $files = FileBag::normalize([
            'avatar' => [
                'name'     => 'me.png',
                'type'     => 'image/png',
                'tmp_name' => $path,
                'error'    => UPLOAD_ERR_OK,
                'size'     => 5,
            ],
        ]);

        $this->assertInstanceOf(UploadedFile::class, $files['avatar']);
        $this->assertSame('me.png', $files['avatar']->getClientOriginalName());
        $this->assertSame('png', $files['avatar']->getClientOriginalExtension());
        $this->assertSame('image/png', $files['avatar']->getClientMimeType());
    }

    /**
     * PHP pivots array inputs by property, not by index. If that isn't rotated
     * back, docs[0] is the string 'a.pdf' instead of a file.
     */
    public function test_array_input_is_unpivoted(): void
    {
        $a = $this->tempFile();
        $b = $this->tempFile();

        $files = FileBag::normalize([
            'docs' => [
                'name'     => ['a.pdf', 'b.pdf'],
                'type'     => ['application/pdf', 'application/pdf'],
                'tmp_name' => [$a, $b],
                'error'    => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'size'     => [10, 20],
            ],
        ]);

        $this->assertIsArray($files['docs']);
        $this->assertCount(2, $files['docs']);
        $this->assertInstanceOf(UploadedFile::class, $files['docs'][0]);
        $this->assertSame('a.pdf', $files['docs'][0]->getClientOriginalName());
        $this->assertSame('b.pdf', $files['docs'][1]->getClientOriginalName());
    }

    public function test_named_keys_in_array_input_are_preserved(): void
    {
        $cover = $this->tempFile();
        $back  = $this->tempFile();

        $files = FileBag::normalize([
            'photos' => [
                'name'     => ['cover' => 'c.jpg', 'back' => 'b.jpg'],
                'type'     => ['cover' => 'image/jpeg', 'back' => 'image/jpeg'],
                'tmp_name' => ['cover' => $cover, 'back' => $back],
                'error'    => ['cover' => UPLOAD_ERR_OK, 'back' => UPLOAD_ERR_OK],
                'size'     => ['cover' => 1, 'back' => 2],
            ],
        ]);

        $this->assertSame('c.jpg', $files['photos']['cover']->getClientOriginalName());
        $this->assertSame('b.jpg', $files['photos']['back']->getClientOriginalName());
    }

    /** An untouched file input posts UPLOAD_ERR_NO_FILE and is not a file. */
    public function test_empty_input_becomes_null(): void
    {
        $files = FileBag::normalize([
            'avatar' => [
                'name'     => '',
                'type'     => '',
                'tmp_name' => '',
                'error'    => UPLOAD_ERR_NO_FILE,
                'size'     => 0,
            ],
        ]);

        $this->assertNull($files['avatar']);
    }

    // ─── Request surface ──────────────────────────────────

    public function test_request_file_and_has_file(): void
    {
        $path = $this->tempFile();

        $request = new Request('POST', '/upload', [], [], [], [
            'avatar' => [
                'name'     => 'me.png',
                'type'     => 'image/png',
                'tmp_name' => $path,
                'error'    => UPLOAD_ERR_OK,
                'size'     => 5,
            ],
        ]);

        $this->assertTrue($request->hasFile('avatar'));
        $this->assertInstanceOf(UploadedFile::class, $request->file('avatar'));
        $this->assertFalse($request->hasFile('nope'));
        $this->assertNull($request->file('nope'));
        $this->assertSame('fallback', $request->file('nope', 'fallback'));
    }

    public function test_request_file_reaches_array_inputs_with_dot_notation(): void
    {
        $a = $this->tempFile();
        $b = $this->tempFile();

        $request = new Request('POST', '/upload', [], [], [], [
            'docs' => [
                'name'     => ['a.pdf', 'b.pdf'],
                'type'     => ['application/pdf', 'application/pdf'],
                'tmp_name' => [$a, $b],
                'error'    => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'size'     => [10, 20],
            ],
        ]);

        $this->assertTrue($request->hasFile('docs'));
        $this->assertCount(2, $request->file('docs'));
        $this->assertSame('b.pdf', $request->file('docs.1')->getClientOriginalName());
    }

    public function test_empty_upload_is_not_reported_as_present(): void
    {
        $request = new Request('POST', '/upload', [], [], [], [
            'avatar' => [
                'name'     => '',
                'type'     => '',
                'tmp_name' => '',
                'error'    => UPLOAD_ERR_NO_FILE,
                'size'     => 0,
            ],
        ]);

        $this->assertFalse($request->hasFile('avatar'));
    }

    public function test_all_includes_uploaded_files(): void
    {
        $path = $this->tempFile();

        $request = new Request('POST', '/upload', [], [], ['title' => 'Hi'], [
            'avatar' => [
                'name'     => 'me.png',
                'type'     => 'image/png',
                'tmp_name' => $path,
                'error'    => UPLOAD_ERR_OK,
                'size'     => 5,
            ],
        ]);

        $all = $request->all();

        $this->assertSame('Hi', $all['title']);
        $this->assertInstanceOf(UploadedFile::class, $all['avatar']);
    }

    // ─── UploadedFile ─────────────────────────────────────

    public function test_invalid_upload_reports_its_error(): void
    {
        $file = new UploadedFile('/nonexistent', 'big.zip', 'application/zip', UPLOAD_ERR_INI_SIZE);

        $this->assertFalse($file->isValid());
        $this->assertSame(UPLOAD_ERR_INI_SIZE, $file->getError());
        $this->assertStringContainsString('upload_max_filesize', $file->getErrorMessage());
        $this->assertFalse($file->getSize());
        $this->assertFalse($file->store('avatars'));
    }

    public function test_hash_name_is_random_and_keeps_an_extension(): void
    {
        $file = UploadedFile::fake($this->tempFile('x', '.txt'), 'notes.txt');

        $first  = $file->hashName();
        $second = $file->hashName();

        $this->assertNotSame($first, $second);
        $this->assertStringEndsWith('.txt', $first);
        $this->assertStringStartsWith('uploads/', $file->hashName('uploads'));
    }

    /** The client name is never reused: it can collide or carry separators. */
    public function test_hash_name_does_not_reuse_the_client_name(): void
    {
        $file = UploadedFile::fake($this->tempFile(), '../../etc/passwd');

        $this->assertStringNotContainsString('passwd', $file->hashName());
        $this->assertStringNotContainsString('..', $file->hashName());
    }

    public function test_mime_type_is_sniffed_not_taken_from_the_client(): void
    {
        // Claims to be a PNG; contents are plain text.
        $path = $this->tempFile('just text', '.png');
        $file = new UploadedFile($path, 'evil.png', 'image/png', UPLOAD_ERR_OK, true);

        $this->assertSame('image/png', $file->getClientMimeType());
        $this->assertStringContainsString('text/', (string) $file->getMimeType());
    }

    public function test_move_relocates_the_file(): void
    {
        $file = UploadedFile::fake($this->tempFile('payload', '.txt'), 'notes.txt');
        $target = sys_get_temp_dir() . '/nitro_moved_' . bin2hex(random_bytes(4));

        $moved = $file->move($target, 'final.txt');
        $this->tempFiles[] = $moved->getPathname();

        $this->assertFileExists($moved->getPathname());
        $this->assertSame('payload', file_get_contents($moved->getPathname()));

        @rmdir($target);
    }

    // ─── MimeTypes ────────────────────────────────────────

    public function test_mime_type_mapping(): void
    {
        $this->assertSame('jpg', MimeTypes::extension('image/jpeg'));
        $this->assertSame('pdf', MimeTypes::extension('application/pdf'));
        $this->assertSame('jpg', MimeTypes::extension('image/jpeg; charset=binary'));
        $this->assertNull(MimeTypes::extension('application/x-unknown-thing'));

        $this->assertContains('image/jpeg', MimeTypes::typesFor('jpg'));
        $this->assertContains('image/jpeg', MimeTypes::typesFor('.jpeg'));

        $this->assertTrue(MimeTypes::isImage('image/png'));
        $this->assertFalse(MimeTypes::isImage('application/pdf'));
    }
}
