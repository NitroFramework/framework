<?php

namespace Nitro\Filesystem\Signers;

/**
 * AWS Signature Version 4 for S3-compatible object storage.
 *
 * Two forms are produced: signed request headers, for calls the server makes,
 * and a presigned URL, for handing a browser temporary access to one object.
 * Both are pure functions of the request and the credentials — nothing here
 * performs I/O, so the signer can be exercised against the published test
 * vectors without a bucket.
 */
class AwsSignatureV4
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    /** The hash S3 accepts in place of a body digest on a presigned URL. */
    private const UNSIGNED = 'UNSIGNED-PAYLOAD';

    public function __construct(
        private string $key,
        private string $secret,
        private string $region,
        private string $service = 's3',
        private ?string $token = null,
    ) {}

    /**
     * Headers that authenticate one request, including the ones signed.
     *
     * @param  array<string, string> $headers Headers already on the request.
     * @param  array<string, string> $query   Query parameters, unencoded.
     * @return array<string, string>
     */
    public function headers(
        string $method,
        string $url,
        array $headers = [],
        array $query = [],
        string $body = '',
        ?int $timestamp = null,
    ): array {
        $timestamp ??= time();
        $longDate = gmdate('Ymd\THis\Z', $timestamp);
        $shortDate = gmdate('Ymd', $timestamp);

        $payloadHash = hash('sha256', $body);

        $headers['Host'] = (string) parse_url($url, PHP_URL_HOST);
        $headers['x-amz-date'] = $longDate;

        // S3 requires the body digest as a header and signs it; other services
        // sign the digest only as part of the canonical request.
        if ($this->service === 's3') {
            $headers['x-amz-content-sha256'] = $payloadHash;
        }

        if ($this->token !== null && $this->token !== '') {
            $headers['x-amz-security-token'] = $this->token;
        }

        [$canonicalHeaders, $signedHeaders] = $this->canonicalHeaders($headers);

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $this->canonicalPath($url),
            $this->canonicalQuery($query),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = $shortDate . '/' . $this->region . '/' . $this->service . '/aws4_request';

        $signature = hash_hmac(
            'sha256',
            implode("\n", [self::ALGORITHM, $longDate, $scope, hash('sha256', $canonicalRequest)]),
            $this->signingKey($shortDate),
        );

        $headers['Authorization'] = self::ALGORITHM
            . ' Credential=' . $this->key . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        return $headers;
    }

    /**
     * A URL that grants access to one object for $expires seconds.
     *
     * The credentials travel in the query string rather than a header, so the
     * link works from a browser. Only `host` is signed, which is why the URL
     * stops working if it is moved to another endpoint.
     *
     * @param array<string, string> $query Extra parameters to sign into the URL.
     */
    public function presign(
        string $method,
        string $url,
        int $expires,
        array $query = [],
        ?int $timestamp = null,
    ): string {
        $timestamp ??= time();
        $longDate = gmdate('Ymd\THis\Z', $timestamp);
        $shortDate = gmdate('Ymd', $timestamp);
        $scope = $shortDate . '/' . $this->region . '/' . $this->service . '/aws4_request';

        $query['X-Amz-Algorithm'] = self::ALGORITHM;
        $query['X-Amz-Credential'] = $this->key . '/' . $scope;
        $query['X-Amz-Date'] = $longDate;
        $query['X-Amz-Expires'] = (string) max(1, $expires);
        $query['X-Amz-SignedHeaders'] = 'host';

        if ($this->token !== null && $this->token !== '') {
            $query['X-Amz-Security-Token'] = $this->token;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $this->canonicalPath($url),
            $this->canonicalQuery($query),
            'host:' . $host . "\n",
            'host',
            $this->service === 's3' ? self::UNSIGNED : hash('sha256', ''),
        ]);

        $signature = hash_hmac(
            'sha256',
            implode("\n", [self::ALGORITHM, $longDate, $scope, hash('sha256', $canonicalRequest)]),
            $this->signingKey($shortDate),
        );

        $query['X-Amz-Signature'] = $signature;

        return $url . '?' . $this->canonicalQuery($query);
    }

    /**
     * Derive the date/region/service-scoped signing key.
     *
     * Chaining four HMACs is what keeps the account's secret out of the
     * signature: the key that signs a request is only valid for one day, one
     * region and one service.
     */
    private function signingKey(string $shortDate): string
    {
        $key = hash_hmac('sha256', $shortDate, 'AWS4' . $this->secret, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', $this->service, $key, true);

        return hash_hmac('sha256', 'aws4_request', $key, true);
    }

    /**
     * Lower-cased, sorted headers plus the list of names that were signed.
     *
     * @param  array<string, string> $headers
     * @return array{0: string, 1: string}
     */
    private function canonicalHeaders(array $headers): array
    {
        $normalised = [];

        foreach ($headers as $name => $value) {
            $normalised[strtolower($name)] = trim((string) $value);
        }

        ksort($normalised);

        $canonical = '';

        foreach ($normalised as $name => $value) {
            $canonical .= $name . ':' . preg_replace('/\s+/', ' ', $value) . "\n";
        }

        return [$canonical, implode(';', array_keys($normalised))];
    }

    /**
     * The path exactly as it appears in the URL.
     *
     * The caller encodes the key once, with {@see static::encodeKey()}, and
     * that same encoding is what gets signed. Re-encoding here would produce a
     * signature over a path the server never sees.
     */
    private function canonicalPath(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_PATH) ?: '/');
    }

    /**
     * Percent-encode an object key for use in a URL path.
     *
     * Slashes stay literal because they separate the segments S3 treats as key
     * structure. rawurlencode leaves exactly the characters RFC 3986 calls
     * unreserved, which is the set the signing rules also exempt.
     */
    public static function encodeKey(string $key): string
    {
        return str_replace('%2F', '/', rawurlencode($key));
    }

    /**
     * Query parameters sorted by name and percent-encoded.
     *
     * @param array<string, string> $query
     */
    private function canonicalQuery(array $query): string
    {
        ksort($query);

        $pairs = [];

        foreach ($query as $name => $value) {
            $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $pairs);
    }
}
