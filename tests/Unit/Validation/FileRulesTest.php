<?php

namespace Tests\Unit\Validation;

use Nitro\Http\UploadedFile;
use Nitro\Validation\Validator;
use PHPUnit\Framework\TestCase;

/**
 * file / image / mimes / mimetypes, and size rules applied to uploads.
 */
class FileRulesTest extends TestCase
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

    private function upload(string $contents, string $name): UploadedFile
    {
        $path = sys_get_temp_dir() . '/nitro_vr_' . bin2hex(random_bytes(6));
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return UploadedFile::fake($path, $name);
    }

    /** A 1x1 PNG, so the MIME sniffer sees real image bytes. */
    private function pngUpload(string $name = 'pixel.png'): UploadedFile
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        return $this->upload($png, $name);
    }

    private function passes(mixed $value, string $rules): bool
    {
        return ! (new Validator(["doc" => $value], ["doc" => $rules]))->fails();
    }

    // ─── file ─────────────────────────────────────────────

    public function test_file_rule_accepts_a_valid_upload(): void
    {
        $this->assertTrue($this->passes($this->upload('text', 'a.txt'), 'file'));
    }

    public function test_file_rule_rejects_a_non_file(): void
    {
        $this->assertFalse($this->passes('just a string', 'file'));
    }

    public function test_file_rule_rejects_a_failed_upload(): void
    {
        $failed = new UploadedFile('/nonexistent', 'big.zip', 'application/zip', UPLOAD_ERR_INI_SIZE);

        $this->assertFalse($this->passes($failed, 'file'));
    }

    // ─── image ────────────────────────────────────────────

    public function test_image_rule_accepts_a_real_png(): void
    {
        $this->assertTrue($this->passes($this->pngUpload(), 'image'));
    }

    /**
     * The classic upload bug: a script renamed to .png. The client name and
     * client MIME both say image; the bytes do not.
     */
    public function test_image_rule_rejects_a_renamed_non_image(): void
    {
        $fake = $this->upload('<?php echo "pwned";', 'shell.png');

        $this->assertFalse($this->passes($fake, 'image'));
    }

    // ─── mimes ────────────────────────────────────────────

    public function test_mimes_matches_on_sniffed_contents(): void
    {
        $png = $this->pngUpload();

        $this->assertTrue($this->passes($png, 'mimes:png'));
        $this->assertTrue($this->passes($png, 'mimes:jpg,png,gif'));
        $this->assertFalse($this->passes($png, 'mimes:pdf'));
    }

    public function test_mimes_rejects_a_misnamed_file(): void
    {
        $this->assertFalse($this->passes($this->upload('plain text', 'doc.pdf'), 'mimes:pdf'));
    }

    public function test_mimes_accepts_either_spelling_of_jpeg(): void
    {
        $this->assertSame(
            ['jpg', 'jpeg', 'jpe'],
            \Nitro\Http\MimeTypes::extensions('image/jpeg')
        );
    }

    // ─── mimetypes ────────────────────────────────────────

    public function test_mimetypes_exact_and_wildcard(): void
    {
        $png = $this->pngUpload();

        $this->assertTrue($this->passes($png, 'mimetypes:image/png'));
        $this->assertTrue($this->passes($png, 'mimetypes:image/*'));
        $this->assertFalse($this->passes($png, 'mimetypes:application/pdf'));
    }

    // ─── size rules on uploads ────────────────────────────

    public function test_max_measures_uploads_in_kilobytes(): void
    {
        // 3 KB of payload.
        $file = $this->upload(str_repeat('x', 3072), 'big.txt');

        $this->assertTrue($this->passes($file, 'max:4'));
        $this->assertFalse($this->passes($file, 'max:2'));
    }

    public function test_min_measures_uploads_in_kilobytes(): void
    {
        $file = $this->upload(str_repeat('x', 3072), 'big.txt');

        $this->assertTrue($this->passes($file, 'min:2'));
        $this->assertFalse($this->passes($file, 'min:4'));
    }

    public function test_max_still_counts_string_characters(): void
    {
        $this->assertTrue($this->passes('abc', 'max:3'));
        $this->assertFalse($this->passes('abcd', 'max:3'));
    }

    public function test_max_counts_array_items(): void
    {
        $this->assertTrue($this->passes([1, 2], 'max:2'));
        $this->assertFalse($this->passes([1, 2, 3], 'max:2'));
    }

    public function test_size_message_names_the_right_unit(): void
    {
        $file = $this->upload(str_repeat('x', 3072), 'big.txt');

        $validator = new Validator(["doc" => $file], ["doc" => "max:1"]);
        $validator->fails();

        $this->assertStringContainsString('kilobytes', (string) $validator->errors()->first('doc'));
    }

    // ─── nullable interaction ─────────────────────────────

    public function test_absent_file_passes_when_not_required(): void
    {
        $this->assertTrue($this->passes(null, 'file'));
        $this->assertTrue($this->passes(null, 'image'));
        $this->assertTrue($this->passes(null, 'mimes:png'));
    }
}
