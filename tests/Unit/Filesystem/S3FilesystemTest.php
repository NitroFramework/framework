<?php

namespace Tests\Unit\Filesystem;

use Nitro\Filesystem\S3Filesystem;
use Nitro\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The S3 disk, driven against a faked HTTP transport.
 *
 * What is asserted here is the shape of the call the disk makes — verb, URL and
 * signed headers — because that is the whole of the driver's contract with the
 * storage service.
 */
class S3FilesystemTest extends TestCase
{
    private HttpFactory $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = (new HttpFactory())->fake();
    }

    private function disk(array $overrides = []): S3Filesystem
    {
        return new S3Filesystem($overrides + [
            'key'    => 'AKIDEXAMPLE',
            'secret' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            'region' => 'us-east-1',
            'bucket' => 'files',
        ], $this->http);
    }

    // ─── Configuration ────────────────────────────────────

    public function test_missing_credentials_are_reported_at_construction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[secret]');

        new S3Filesystem(['key' => 'k', 'bucket' => 'b'], $this->http);
    }

    public function test_the_bucket_is_addressed_as_a_subdomain_by_default(): void
    {
        $this->disk()->get('a.txt');

        $this->http->assertSent(fn (array $request): bool
            => $request['url'] === 'https://files.s3.us-east-1.amazonaws.com/a.txt');
    }

    public function test_path_style_puts_the_bucket_in_the_path(): void
    {
        $this->disk([
            'endpoint' => 'http://localhost:9000',
            'use_path_style_endpoint' => true,
        ])->get('a.txt');

        $this->http->assertSent(fn (array $request): bool
            => $request['url'] === 'http://localhost:9000/files/a.txt');
    }

    public function test_a_root_prefixes_every_key(): void
    {
        $this->disk(['root' => 'tenant-1'])->get('a.txt');

        $this->http->assertSent(fn (array $request): bool
            => str_ends_with($request['url'], '/tenant-1/a.txt'));
    }

    // ─── Reading ──────────────────────────────────────────

    public function test_get_returns_the_body(): void
    {
        $this->http->fake(['*' => $this->http->response('file contents')]);

        $this->assertSame('file contents', $this->disk()->get('a.txt'));
    }

    public function test_get_returns_null_when_the_object_is_missing(): void
    {
        $this->http->fake(['*' => $this->http->response('', 404)]);

        $this->assertNull($this->disk()->get('a.txt'));
    }

    public function test_exists_asks_for_the_headers_only(): void
    {
        $this->assertTrue($this->disk()->exists('a.txt'));

        $this->http->assertSent(fn (array $request): bool => $request['method'] === 'HEAD');
    }

    public function test_missing_is_the_inverse_of_exists(): void
    {
        $this->http->fake(['*' => $this->http->response('', 404)]);

        $this->assertTrue($this->disk()->missing('a.txt'));
    }

    public function test_size_and_last_modified_come_from_the_headers(): void
    {
        $this->http->fake(['*' => $this->http->response('', 200, [
            'Content-Length' => '2048',
            'Last-Modified'  => 'Sun, 24 May 2015 00:00:00 GMT',
        ])]);

        $disk = $this->disk();

        $this->assertSame(2048, $disk->size('a.txt'));
        $this->assertSame(strtotime('Sun, 24 May 2015 00:00:00 GMT'), $disk->lastModified('a.txt'));
    }

    public function test_size_is_null_for_a_missing_object(): void
    {
        $this->http->fake(['*' => $this->http->response('', 404)]);

        $this->assertNull($this->disk()->size('a.txt'));
    }

    // ─── Writing ──────────────────────────────────────────

    public function test_put_sends_the_body(): void
    {
        $this->assertTrue($this->disk()->put('a.txt', 'hello'));

        $this->http->assertSent(fn (array $request): bool
            => $request['method'] === 'PUT' && $request['body'] === 'hello');
    }

    public function test_put_signs_the_body_digest(): void
    {
        $this->disk()->put('a.txt', 'hello');

        $this->http->assertSent(fn (array $request): bool
            => $request['headers']['x-amz-content-sha256'] === hash('sha256', 'hello'));
    }

    public function test_a_stream_is_read_before_it_is_sent(): void
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, 'from a stream');
        rewind($stream);

        $this->disk()->put('a.txt', $stream);

        fclose($stream);

        $this->http->assertSent(fn (array $request): bool => $request['body'] === 'from a stream');
    }

    public function test_public_visibility_asks_for_a_public_acl(): void
    {
        $this->disk()->put('a.txt', 'hello', ['visibility' => 'public']);

        $this->http->assertSent(fn (array $request): bool
            => ($request['headers']['x-amz-acl'] ?? null) === 'public-read');
    }

    public function test_private_is_the_default_visibility(): void
    {
        $this->disk()->put('a.txt', 'hello');

        $this->http->assertSent(fn (array $request): bool
            => ! isset($request['headers']['x-amz-acl']));
    }

    public function test_copy_names_the_source_object(): void
    {
        $this->assertTrue($this->disk()->copy('a.txt', 'b.txt'));

        $this->http->assertSent(fn (array $request): bool
            => $request['method'] === 'PUT'
            && ($request['headers']['x-amz-copy-source'] ?? null) === '/files/a.txt'
            && str_ends_with($request['url'], '/b.txt'));
    }

    public function test_move_copies_then_deletes(): void
    {
        $this->assertTrue($this->disk()->move('a.txt', 'b.txt'));

        $this->http->assertSentCount(2);
        $this->http->assertSent(fn (array $request): bool
            => $request['method'] === 'DELETE' && str_ends_with($request['url'], '/a.txt'));
    }

    public function test_several_paths_are_deleted_in_one_call(): void
    {
        $this->assertTrue($this->disk()->delete(['a.txt', 'b.txt']));

        $this->http->assertSentCount(2);
    }

    public function test_a_failed_delete_is_reported(): void
    {
        $this->http->fake(['*' => $this->http->response('', 403)]);

        $this->assertFalse($this->disk()->delete('a.txt'));
    }

    public function test_make_directory_stores_a_marker_object(): void
    {
        $this->assertTrue($this->disk()->makeDirectory('photos'));

        $this->http->assertSent(fn (array $request): bool
            => $request['method'] === 'PUT' && str_ends_with($request['url'], '/photos/'));
    }

    // ─── Listing ──────────────────────────────────────────

    public function test_a_listing_separates_files_from_prefixes(): void
    {
        $this->http->fake(['*' => $this->http->response(
            '<ListBucketResult><IsTruncated>false</IsTruncated>'
                . '<Contents><Key>photos/one.jpg</Key></Contents>'
                . '<Contents><Key>photos/two.jpg</Key></Contents>'
                . '<Contents><Key>photos/</Key></Contents>'
                . '<CommonPrefixes><Prefix>photos/2024/</Prefix></CommonPrefixes>'
                . '</ListBucketResult>'
        )]);

        $disk = $this->disk();

        $this->assertSame(['photos/one.jpg', 'photos/two.jpg'], $disk->files('photos'));
        $this->assertSame(['photos/2024'], $disk->directories('photos'));
    }

    public function test_a_non_recursive_listing_asks_for_a_delimiter(): void
    {
        $this->disk()->files('photos');

        $this->http->assertSent(fn (array $request): bool
            => str_contains($request['url'], 'delimiter=%2F')
            && str_contains($request['url'], 'prefix=photos%2F'));
    }

    public function test_a_recursive_listing_asks_for_no_delimiter(): void
    {
        $this->disk()->allFiles('photos');

        $this->http->assertSent(fn (array $request): bool
            => ! str_contains($request['url'], 'delimiter'));
    }

    public function test_a_truncated_listing_is_followed(): void
    {
        $page = 0;

        $this->http->fake(['*' => function (array $request) use (&$page) {
            $page++;

            return $this->http->response($page === 1
                ? '<ListBucketResult><IsTruncated>true</IsTruncated>'
                    . '<NextContinuationToken>next</NextContinuationToken>'
                    . '<Contents><Key>a.txt</Key></Contents></ListBucketResult>'
                : '<ListBucketResult><IsTruncated>false</IsTruncated>'
                    . '<Contents><Key>b.txt</Key></Contents></ListBucketResult>');
        }]);

        $this->assertSame(['a.txt', 'b.txt'], $this->disk()->allFiles());
        $this->assertSame(2, $page);
    }

    public function test_a_root_is_stripped_from_listed_paths(): void
    {
        $this->http->fake(['*' => $this->http->response(
            '<ListBucketResult><IsTruncated>false</IsTruncated>'
                . '<Contents><Key>tenant-1/a.txt</Key></Contents></ListBucketResult>'
        )]);

        $this->assertSame(['a.txt'], $this->disk(['root' => 'tenant-1'])->allFiles());
    }

    // ─── Addressing ───────────────────────────────────────

    public function test_url_uses_the_configured_public_base(): void
    {
        $this->assertSame(
            'https://cdn.example.com/a b.txt',
            urldecode($this->disk(['url' => 'https://cdn.example.com/'])->url('a b.txt')),
        );
    }

    public function test_url_falls_back_to_the_endpoint(): void
    {
        $this->assertSame(
            'https://files.s3.us-east-1.amazonaws.com/a.txt',
            $this->disk()->url('a.txt'),
        );
    }

    public function test_a_temporary_url_carries_a_signature_and_an_expiry(): void
    {
        $url = $this->disk()->temporaryUrl('a.txt', 900);

        $this->assertStringContainsString('X-Amz-Expires=900', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
        $this->assertStringStartsWith('https://files.s3.us-east-1.amazonaws.com/a.txt?', $url);
    }

    public function test_path_returns_the_object_key(): void
    {
        $this->assertSame('tenant-1/a.txt', $this->disk(['root' => 'tenant-1'])->path('a.txt'));
    }
}
