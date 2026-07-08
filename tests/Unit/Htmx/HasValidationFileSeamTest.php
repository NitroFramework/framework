<?php

namespace Tests\Unit\Htmx;

use Nitro\Container\Container;
use Nitro\Foundation\Application;
use Nitro\Htmx\HtmxComponent;
use Nitro\Htmx\Support\HxValidationErrors;
use Nitro\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the file-upload seam. HasValidation must source
 * uploaded files from the request (app('request')->allFiles()) — the same
 * seam the rest of the framework uses — and never touch $_FILES directly.
 *
 * The two "conflict" tests are the teeth: they put opposite values in the
 * request and in $_FILES, then assert validation follows the request. If
 * someone reintroduces a raw $_FILES read in HxValidator, these fail.
 */
class HasValidationFileSeamTest extends TestCase
{
    private array $savedFiles;

    protected function setUp(): void
    {
        $this->savedFiles = $_FILES;
        Container::reset();
        (new Application(dirname(__DIR__, 3)))->bootstrap();
    }

    protected function tearDown(): void
    {
        $_FILES = $this->savedFiles;
        restore_error_handler();
        restore_exception_handler();
        Container::reset();
    }

    /** Bind a request carrying the given normalized ($_FILES-shaped) uploads. */
    private function bindRequest(array $files): void
    {
        $request = new Request(
            method: 'POST',
            path: '/upload',
            headers: ['hx-request' => 'true'],
            query: [],
            body: [],
            files: $files,
            server: [],
        );
        Container::getInstance()->singleton('request', fn () => $request);
    }

    private function component(): HtmxComponent
    {
        return new class extends HtmxComponent {
            public function runValidation(array $rules): HxValidationErrors
            {
                return $this->validate($rules);
            }
        };
    }

    private function upload(string $name = 'photo.png', int $size = 512, int $error = UPLOAD_ERR_OK): array
    {
        return ['name' => $name, 'size' => $size, 'error' => $error, 'tmp_name' => ''];
    }

    public function test_required_is_satisfied_by_a_request_upload(): void
    {
        $this->bindRequest(['avatar' => $this->upload()]);
        $_FILES = []; // prove independence from the superglobal

        $errors = $this->component()->runValidation(['avatar' => 'required']);
        $this->assertFalse($errors->any(), 'an uploaded file in the request should satisfy required');
    }

    public function test_superglobal_upload_is_ignored_when_request_has_none(): void
    {
        $this->bindRequest([]);                 // request sees no files
        $_FILES = ['avatar' => $this->upload()]; // but the superglobal does

        $errors = $this->component()->runValidation(['avatar' => 'required']);
        $this->assertTrue(
            $errors->has('avatar'),
            'validation must read the request seam, not $_FILES — required should still fail'
        );
    }

    public function test_file_metadata_rules_flow_from_the_request(): void
    {
        $this->bindRequest(['doc' => $this->upload('malware.exe')]);
        $_FILES = ['doc' => $this->upload('safe.pdf')]; // decoy

        $errors = $this->component()->runValidation(['doc' => 'mimes:pdf,docx']);
        $this->assertTrue($errors->has('doc'), 'extension check must use the request file, not $_FILES');
        $this->assertStringContainsString('pdf', $errors->first('doc'));
    }

    public function test_max_size_rule_reads_request_upload_size(): void
    {
        $this->bindRequest(['f' => $this->upload(size: 5 * 1024 * 1024)]); // 5 MB
        $_FILES = [];

        $errors = $this->component()->runValidation(['f' => 'max_size:1mb']);
        $this->assertTrue($errors->has('f'));
    }
}
