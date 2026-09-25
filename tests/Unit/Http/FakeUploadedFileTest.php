<?php

namespace Tests\Unit\Http;

use Nitro\Http\Testing\File;
use Nitro\Http\Testing\FileFactory;
use Nitro\Http\Testing\MimeType;
use Nitro\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * Fake uploads made from nothing, for tests of code that accepts files.
 */
class FakeUploadedFileTest extends TestCase
{
    public function test_fake_with_no_path_is_the_factory(): void
    {
        $this->assertInstanceOf(FileFactory::class, UploadedFile::fake());
    }

    public function test_fake_with_a_path_still_wraps_that_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nitro');
        file_put_contents($path, 'hello');

        $file = UploadedFile::fake($path, 'notes.txt');

        $this->assertNotInstanceOf(File::class, $file);
        $this->assertSame('notes.txt', $file->getClientOriginalName());

        unlink($path);
    }

    public function test_create_reports_the_size_it_was_given(): void
    {
        $file = UploadedFile::fake()->create('report.pdf', 512);

        $this->assertInstanceOf(UploadedFile::class, $file);
        $this->assertSame('report.pdf', $file->getClientOriginalName());
        $this->assertSame(512 * 1024, $file->getSize());
        $this->assertTrue($file->isValid());
    }

    public function test_the_mime_type_follows_the_name_unless_given(): void
    {
        $this->assertSame('application/pdf', UploadedFile::fake()->create('report.pdf')->getMimeType());
        $this->assertSame('text/plain', UploadedFile::fake()->create('report.pdf', 1, 'text/plain')->getMimeType());
        $this->assertSame('application/octet-stream', UploadedFile::fake()->create('blob.unknownext')->getMimeType());
    }

    public function test_size_and_mime_type_can_be_set_after(): void
    {
        $file = UploadedFile::fake()->create('a.txt')->size(3)->mimeType('image/png');

        $this->assertSame(3 * 1024, $file->getSize());
        $this->assertSame('image/png', $file->getMimeType());
    }

    public function test_create_with_content_holds_it(): void
    {
        $file = UploadedFile::fake()->createWithContent('rows.csv', "id,name\n1,Ada\n");

        $this->assertSame("id,name\n1,Ada\n", file_get_contents($file->getPathname()));
        $this->assertSame(strlen("id,name\n1,Ada\n"), $file->getSize());
    }

    public function test_create_given_a_string_holds_it_as_content(): void
    {
        $file = UploadedFile::fake()->create('a.txt', 'abc');

        $this->assertSame('abc', file_get_contents($file->getPathname()));
    }

    public function test_image_is_a_real_image_of_that_size(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not installed.');
        }

        $file = UploadedFile::fake()->image('avatar.png', 40, 30);

        [$width, $height, $type] = getimagesize($file->getPathname());

        $this->assertSame([40, 30, IMAGETYPE_PNG], [$width, $height, $type]);
        $this->assertSame('image/png', $file->getMimeType());
    }

    public function test_an_image_named_with_another_extension_is_a_jpeg(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not installed.');
        }

        $file = File::image('photo.jpg');

        $this->assertSame(IMAGETYPE_JPEG, getimagesize($file->getPathname())[2]);
    }

    public function test_the_static_shortcuts_make_the_same_files(): void
    {
        $this->assertSame(2048, File::create('a.bin', 2)->getSize());

        // Held in a variable: the temporary file goes when the object does.
        $file = File::createWithContent('a.txt', 'x');

        $this->assertSame('x', file_get_contents($file->getPathname()));
    }

    public function test_mime_type_lookup(): void
    {
        $this->assertSame('image/png', MimeType::from('a/b/avatar.png'));
        $this->assertSame('png', MimeType::search('image/png'));
    }
}
