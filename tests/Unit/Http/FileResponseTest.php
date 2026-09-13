<?php

namespace Tests\Unit\Http;

use Nitro\Http\FileResponse;
use PHPUnit\Framework\TestCase;

/**
 * Handing over a file.
 *
 * The things that go wrong here are all in the headers: a name the browser
 * mangles, a missing length so the progress bar never moves, a content type
 * that makes a PDF open as a text file.
 */
class FileResponseTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/nitro-file-response-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);

        parent::tearDown();
    }

    /** Send it, capturing the body rather than printing it into the run. */
    private function send(FileResponse $response): string
    {
        ob_start();
        $response->send();

        return (string) ob_get_clean();
    }

    private function file(string $name, string $contents = 'hello'): string
    {
        $path = $this->directory . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_a_download_is_offered_as_an_attachment(): void
    {
        $response = FileResponse::download($this->file('report.pdf'));
        $this->send($response);

        $this->assertStringStartsWith('attachment;', $response->header('Content-Disposition'));
    }

    public function test_an_inline_file_is_shown_rather_than_saved(): void
    {
        $response = FileResponse::inline($this->file('report.pdf'));
        $this->send($response);

        $this->assertStringStartsWith('inline;', $response->header('Content-Disposition'));
    }

    public function test_the_name_on_disk_is_not_the_name_offered(): void
    {
        // The stored file is named by id or hash; the customer should get
        // something they can find again in their downloads folder.
        $response = FileResponse::download($this->file('a3f9c1.pdf'), 'LP-7K42-QP19.pdf');
        $this->send($response);

        $this->assertStringContainsString('filename="LP-7K42-QP19.pdf"', $response->header('Content-Disposition'));
    }

    public function test_an_awkward_name_carries_both_forms(): void
    {
        $response = FileResponse::download($this->file('x.pdf'), 'Rôle des "clés", v2.pdf');
        $this->send($response);

        $disposition = $response->header('Content-Disposition');

        // The plain form is what an old client reads, so it must stay ASCII and
        // must not contain a quote that would end the value early.
        $this->assertStringContainsString('filename="R_le des _cl_s_, v2.pdf"', $disposition);

        // The encoded form is the real name, and is what every current browser
        // prefers.
        $this->assertStringContainsString("filename*=UTF-8''R%C3%B4le%20des%20%22cl%C3%A9s%22%2C%20v2.pdf", $disposition);
    }

    public function test_the_type_and_length_are_declared(): void
    {
        $response = FileResponse::download($this->file('report.pdf', 'a quarter of a page'));
        $this->send($response);

        $this->assertSame('application/pdf', $response->header('Content-Type'));
        $this->assertSame('19', $response->header('Content-Length'));
    }

    public function test_an_explicit_header_wins(): void
    {
        $response = FileResponse::download(
            $this->file('export.csv'),
            null,
            ['Content-Type' => 'text/plain'],
        );

        $this->send($response);

        $this->assertSame('text/plain', $response->header('Content-Type'));
    }

    public function test_the_body_is_the_file(): void
    {
        $response = FileResponse::download($this->file('report.pdf', 'the bytes'));

        $streamed = $this->send($response);

        $this->assertSame('the bytes', $streamed);
        $this->assertSame('the bytes', $response->getContent());
    }

    public function test_a_missing_file_is_a_404_rather_than_an_empty_download(): void
    {
        $response = FileResponse::download($this->directory . '/never-written.pdf');

        $body = $this->send($response);

        // An empty 200 looks to the person receiving it like a corrupt
        // document rather than a missing one.
        $this->assertSame('Not Found', $body);
        $this->assertSame('', $response->getContent());
    }
}
