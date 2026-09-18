<?php

namespace Nitro\Filesystem;

use Nitro\Filesystem\Contracts\Filesystem;
use Nitro\Filesystem\Signers\AwsSignatureV4;
use Nitro\Http\Client\Factory as HttpFactory;
use Nitro\Http\Client\Response;
use RuntimeException;

/**
 * A disk backed by S3-compatible object storage (S3, R2, Spaces, MinIO).
 *
 * Speaks the REST API directly over the HTTP client and signs every call with
 * {@see AwsSignatureV4}, so the disk adds no dependency beyond what the
 * framework already ships. Anything the API expresses as XML — listings, in
 * particular — is read with the SimpleXML that is part of PHP.
 *
 * Config keys:
 *   key, secret, token         Credentials; token only for temporary ones.
 *   region                     Signing region ('auto' for R2).
 *   bucket                     Bucket name.
 *   endpoint                   Full endpoint, for providers other than S3.
 *   use_path_style_endpoint    Put the bucket in the path rather than the host.
 *   url                        Public base URL, for {@see url()}.
 *   root                       Prefix applied to every key.
 *   visibility                 'private' (default) or 'public'.
 */
class S3Filesystem implements Filesystem
{
    protected AwsSignatureV4 $signer;
    protected HttpFactory $http;

    protected string $bucket;
    protected string $endpoint;
    protected bool $pathStyle;
    protected string $root;
    protected ?string $url;
    protected string $defaultVisibility;

    public function __construct(array $config, ?HttpFactory $http = null)
    {
        foreach (['key', 'secret', 'bucket'] as $required) {
            if (empty($config[$required])) {
                throw new RuntimeException("The s3 disk is missing its [{$required}] configuration value.");
            }
        }

        $region = (string) ($config['region'] ?? 'us-east-1');

        $this->signer = new AwsSignatureV4(
            (string) $config['key'],
            (string) $config['secret'],
            $region,
            's3',
            isset($config['token']) ? (string) $config['token'] : null,
        );

        $this->http = $http ?? new HttpFactory();
        $this->bucket = (string) $config['bucket'];
        $this->pathStyle = (bool) ($config['use_path_style_endpoint'] ?? false);
        $this->root = trim((string) ($config['root'] ?? ''), '/');
        $this->url = isset($config['url']) ? rtrim((string) $config['url'], '/') : null;
        $this->defaultVisibility = (string) ($config['visibility'] ?? 'private');

        $this->endpoint = rtrim(
            (string) ($config['endpoint'] ?? "https://s3.{$region}.amazonaws.com"),
            '/',
        );
    }

    // ─── Reading ──────────────────────────────────────────

    public function exists(string $path): bool
    {
        return $this->request('HEAD', $this->key($path))->successful();
    }

    public function missing(string $path): bool
    {
        return ! $this->exists($path);
    }

    public function get(string $path): ?string
    {
        $response = $this->request('GET', $this->key($path));

        return $response->successful() ? $response->body() : null;
    }

    public function size(string $path): ?int
    {
        $response = $this->request('HEAD', $this->key($path));

        if (! $response->successful()) {
            return null;
        }

        $length = $response->header('Content-Length');

        return $length === null ? null : (int) $length;
    }

    public function lastModified(string $path): ?int
    {
        $response = $this->request('HEAD', $this->key($path));

        if (! $response->successful()) {
            return null;
        }

        $modified = $response->header('Last-Modified');

        return $modified === null ? null : (strtotime($modified) ?: null);
    }

    // ─── Writing ──────────────────────────────────────────

    public function put(string $path, mixed $contents, array $options = []): bool
    {
        if (is_resource($contents)) {
            $contents = (string) stream_get_contents($contents);
        }

        $headers = [];

        if (($options['visibility'] ?? $this->defaultVisibility) === 'public') {
            $headers['x-amz-acl'] = 'public-read';
        }

        if (isset($options['mimetype'])) {
            $headers['Content-Type'] = (string) $options['mimetype'];
        }

        return $this->request('PUT', $this->key($path), (string) $contents, $headers)->successful();
    }

    public function putFile(string $directory, string $sourcePath, array $options = []): string
    {
        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);

        return $this->putFileAs(
            $directory,
            $sourcePath,
            bin2hex(random_bytes(16)) . ($extension === '' ? '' : '.' . $extension),
            $options,
        );
    }

    public function putFileAs(string $directory, string $sourcePath, string $name, array $options = []): string
    {
        $path = trim($directory, '/') . '/' . ltrim($name, '/');

        $this->put($path, (string) file_get_contents($sourcePath), $options);

        return $path;
    }

    /**
     * Read, modify, write.
     *
     * Object storage has no append: the whole object is replaced. On a large
     * object this transfers it twice, so it suits log-like text and little else.
     */
    public function append(string $path, string $data): bool
    {
        $existing = $this->get($path);

        return $this->put($path, $existing === null ? $data : $existing . "\n" . $data);
    }

    /** The counterpart of {@see append()}, with the same caveat. */
    public function prepend(string $path, string $data): bool
    {
        $existing = $this->get($path);

        return $this->put($path, $existing === null ? $data : $data . "\n" . $existing);
    }

    public function delete(string|array $paths): bool
    {
        $deleted = true;

        foreach ((array) $paths as $path) {
            $deleted = $this->request('DELETE', $this->key($path))->successful() && $deleted;
        }

        return $deleted;
    }

    public function copy(string $from, string $to): bool
    {
        return $this->request('PUT', $this->key($to), '', [
            'x-amz-copy-source' => '/' . $this->bucket . '/' . $this->key($from),
        ])->successful();
    }

    public function move(string $from, string $to): bool
    {
        return $this->copy($from, $to) && $this->delete($from);
    }

    // ─── Listing ──────────────────────────────────────────

    /** @return array<int, string> */
    public function files(?string $directory = null, bool $recursive = false): array
    {
        return $this->list($directory, $recursive)['files'];
    }

    /** @return array<int, string> */
    public function allFiles(?string $directory = null): array
    {
        return $this->files($directory, true);
    }

    /** @return array<int, string> */
    public function directories(?string $directory = null, bool $recursive = false): array
    {
        return $this->list($directory, $recursive)['directories'];
    }

    /**
     * A directory is only implied by the keys under it, so creating one means
     * storing a zero-length object whose key ends in a slash. Consoles show it
     * as a folder; nothing else depends on it existing.
     */
    public function makeDirectory(string $path): bool
    {
        return $this->request('PUT', $this->key(rtrim($path, '/') . '/'), '')->successful();
    }

    public function deleteDirectory(string $directory): bool
    {
        $keys = $this->allFiles($directory);

        if ($keys === []) {
            return true;
        }

        return $this->delete($keys);
    }

    // ─── Addressing ───────────────────────────────────────

    /** The full object key, including the disk's root prefix. */
    public function path(string $path = ''): string
    {
        return $this->key($path);
    }

    public function url(string $path): string
    {
        if ($this->url !== null) {
            return $this->url . '/' . AwsSignatureV4::encodeKey($this->key($path));
        }

        return $this->endpointFor($this->key($path));
    }

    /**
     * A URL that grants read access to one object for a limited time.
     *
     * This is how a private object reaches a browser without the request
     * passing through the application.
     */
    public function temporaryUrl(string $path, int $seconds = 3600): string
    {
        return $this->signer->presign('GET', $this->endpointFor($this->key($path)), $seconds);
    }

    // ─── Internals ────────────────────────────────────────

    /**
     * Sign and send one API call.
     *
     * @param array<string, string> $headers Extra headers to sign.
     * @param array<string, string> $query   Query parameters to sign.
     */
    protected function request(
        string $method,
        string $key,
        string $body = '',
        array $headers = [],
        array $query = [],
    ): Response {
        $url = $this->endpointFor($key);

        $signed = $this->signer->headers($method, $url, $headers, $query, $body);

        $request = $this->http->withHeaders($signed);

        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $request->send($method, $url, $body === '' ? [] : ['body' => $body]);
    }

    /**
     * Walk the bucket listing, following continuation tokens.
     *
     * @return array{files: array<int, string>, directories: array<int, string>}
     */
    protected function list(?string $directory, bool $recursive): array
    {
        $prefix = $this->key((string) $directory);

        if ($prefix !== '' && ! str_ends_with($prefix, '/')) {
            $prefix .= '/';
        }

        $files = [];
        $directories = [];
        $token = null;

        do {
            $query = ['list-type' => '2', 'prefix' => $prefix];

            if (! $recursive) {
                $query['delimiter'] = '/';
            }

            if ($token !== null) {
                $query['continuation-token'] = $token;
            }

            $response = $this->request('GET', '', '', [], $query);

            if (! $response->successful()) {
                return ['files' => [], 'directories' => []];
            }

            $xml = @simplexml_load_string($response->body());

            if ($xml === false) {
                return ['files' => [], 'directories' => []];
            }

            foreach ($xml->Contents ?? [] as $object) {
                $key = (string) $object->Key;

                // The marker object a makeDirectory() left behind is not a file.
                if (! str_ends_with($key, '/')) {
                    $files[] = $this->stripRoot($key);
                }
            }

            foreach ($xml->CommonPrefixes ?? [] as $common) {
                $directories[] = $this->stripRoot(rtrim((string) $common->Prefix, '/'));
            }

            $token = ((string) ($xml->IsTruncated ?? 'false')) === 'true'
                ? (string) $xml->NextContinuationToken
                : null;
        } while ($token !== null);

        return ['files' => $files, 'directories' => $directories];
    }

    /** The absolute URL an object key is addressed by. */
    protected function endpointFor(string $key): string
    {
        $encoded = AwsSignatureV4::encodeKey($key);

        if ($this->pathStyle) {
            return $this->endpoint . '/' . $this->bucket . '/' . $encoded;
        }

        $host = (string) parse_url($this->endpoint, PHP_URL_HOST);
        $scheme = (string) (parse_url($this->endpoint, PHP_URL_SCHEME) ?: 'https');

        return $scheme . '://' . $this->bucket . '.' . $host . '/' . $encoded;
    }

    /** Prepend the disk's root prefix to a relative path. */
    protected function key(string $path): string
    {
        $path = ltrim($path, '/');

        return $this->root === '' ? $path : $this->root . '/' . $path;
    }

    /** The inverse of {@see key()}, so listings read as disk-relative paths. */
    protected function stripRoot(string $key): string
    {
        if ($this->root === '') {
            return $key;
        }

        return ltrim(substr($key, strlen($this->root)), '/');
    }
}
