<?php

namespace Tests\Unit\Filesystem;

use Nitro\Filesystem\Signers\AwsSignatureV4;
use PHPUnit\Framework\TestCase;

/**
 * Signature Version 4, checked against AWS's own signing test suite.
 *
 * The expected signatures below are taken verbatim from the published vectors
 * (`aws-signing-test-suite/v4`). They are the only way to know the signer is
 * right without a bucket: a signature that is self-consistently wrong looks
 * exactly like one that is correct until a server rejects it.
 */
class AwsSignatureV4Test extends TestCase
{
    private const KEY = 'AKIDEXAMPLE';
    private const SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

    /** The instant every published vector is signed at. */
    private function at(): int
    {
        return strtotime('2015-08-30T12:36:00Z');
    }

    private function signer(?string $token = null, string $service = 'service'): AwsSignatureV4
    {
        return new AwsSignatureV4(self::KEY, self::SECRET, 'us-east-1', $service, $token);
    }

    private function signatureOf(array $headers): string
    {
        preg_match('/Signature=([0-9a-f]+)/', $headers['Authorization'], $matches);

        return $matches[1] ?? '';
    }

    // ─── Header signing ───────────────────────────────────

    public function test_get_vanilla(): void
    {
        $this->assertSame(
            '5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $this->signatureOf($this->signer()->headers(
                'GET',
                'https://example.amazonaws.com/',
                [],
                [],
                '',
                $this->at(),
            )),
        );
    }

    public function test_a_path_of_unreserved_characters_is_left_alone(): void
    {
        $path = '-._~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

        $this->assertSame(
            '07ef7494c76fa4850883e2b006601f940f8a34d404d0cfa977f52a65bbf5f24f',
            $this->signatureOf($this->signer()->headers(
                'GET',
                'https://example.amazonaws.com/' . $path,
                [],
                [],
                '',
                $this->at(),
            )),
        );
    }

    public function test_a_space_in_the_path_is_signed_as_encoded(): void
    {
        $this->assertSame(
            '652487583200325589f1fba4c7e578f72c47cb61beeca81406b39ddec1366741',
            $this->signatureOf($this->signer()->headers(
                'GET',
                'https://example.amazonaws.com/' . AwsSignatureV4::encodeKey('example space/'),
                [],
                [],
                '',
                $this->at(),
            )),
        );
    }

    public function test_a_utf8_path(): void
    {
        $this->assertSame(
            '8318018e0b0f223aa2bbf98705b62bb787dc9c0e678f255a891fd03141be5d85',
            $this->signatureOf($this->signer()->headers(
                'GET',
                'https://example.amazonaws.com/' . AwsSignatureV4::encodeKey("\u{1234}"),
                [],
                [],
                '',
                $this->at(),
            )),
        );
    }

    public function test_query_parameters_are_signed_in_sorted_order(): void
    {
        $this->assertSame(
            'b97d918cfa904a5beff61c982a1b6f458b799221646efd99d3219ec94cdf2500',
            $this->signatureOf($this->signer()->headers(
                'GET',
                'https://example.amazonaws.com/',
                [],
                ['Param2' => 'value2', 'Param1' => 'value1'],
                '',
                $this->at(),
            )),
        );
    }

    public function test_a_session_token_is_signed_in(): void
    {
        $token = '6e86291e8372ff2a2260956d9b8aae1d763fbf315fa00fa31553b73ebf194267';

        $this->assertSame(
            '07ec1639c89043aa0e3e2de82b96708f198cceab042d4a97044c66dd9f74e7f8',
            $this->signatureOf($this->signer($token)->headers(
                'GET',
                'https://example.amazonaws.com/',
                [],
                [],
                '',
                $this->at(),
            )),
        );
    }

    public function test_extra_headers_are_sorted_and_lower_cased(): void
    {
        $this->assertSame(
            'c5410059b04c1ee005303aed430f6e6645f61f4dc9e1461ec8f8916fdf18852c',
            $this->signatureOf($this->signer()->headers(
                'POST',
                'https://example.amazonaws.com/',
                ['My-Header1' => 'value1'],
                [],
                '',
                $this->at(),
            )),
        );
    }

    public function test_header_values_keep_their_case(): void
    {
        $this->assertSame(
            'cdbc9802e29d2942e5e10b5bccfdd67c5f22c7c4e8ae67b53629efa58b974b7d',
            $this->signatureOf($this->signer()->headers(
                'POST',
                'https://example.amazonaws.com/',
                ['My-Header1' => 'VALUE1'],
                [],
                '',
                $this->at(),
            )),
        );
    }

    // ─── Presigned URLs ───────────────────────────────────

    public function test_a_presigned_url(): void
    {
        $url = $this->signer()->presign('GET', 'https://example.amazonaws.com/', 3600, [], $this->at());

        $this->assertStringContainsString(
            'X-Amz-Signature=e93c787ed7f371d5c6b165c1b38ede9550f4dce4144713e844b25b7192d3865d',
            $url,
        );
        $this->assertStringContainsString('X-Amz-Expires=3600', $url);
        $this->assertStringContainsString('X-Amz-SignedHeaders=host', $url);
    }

    /** S3 presigns without a body digest; every other service signs the empty hash. */
    public function test_s3_presigns_an_unsigned_payload(): void
    {
        $s3 = $this->signer(null, 's3')->presign('GET', 'https://b.s3.amazonaws.com/k', 60, [], $this->at());
        $other = $this->signer()->presign('GET', 'https://b.s3.amazonaws.com/k', 60, [], $this->at());

        $this->assertNotSame($s3, $other);
    }

    // ─── Key encoding ─────────────────────────────────────

    public function test_slashes_survive_key_encoding(): void
    {
        $this->assertSame('a/b%20c/d.txt', AwsSignatureV4::encodeKey('a/b c/d.txt'));
    }

    public function test_unreserved_characters_survive_key_encoding(): void
    {
        $this->assertSame('-._~', AwsSignatureV4::encodeKey('-._~'));
    }

    // ─── S3 header signing ────────────────────────────────

    public function test_s3_signs_the_body_digest_as_a_header(): void
    {
        $headers = $this->signer(null, 's3')->headers(
            'PUT',
            'https://bucket.s3.amazonaws.com/file.txt',
            [],
            [],
            'hello',
            $this->at(),
        );

        $this->assertSame(hash('sha256', 'hello'), $headers['x-amz-content-sha256']);
        $this->assertStringContainsString('x-amz-content-sha256', $headers['Authorization']);
    }
}
