<?php

namespace Nitro\Thrust\FrankenPhp;

use RuntimeException;

/**
 * Downloads the FrankenPHP static binary into the project root, mirroring
 * Laravel Octane's installer: it picks the correct release asset for the
 * current OS/arch from the php/frankenphp GitHub releases and streams it to
 * ./frankenphp. Upstream only ships static binaries for Linux and macOS; on
 * Windows, Octane (and therefore Thrust) directs you to WSL or Docker.
 *
 * Dependency-free — uses PHP stream wrappers, no Guzzle.
 */
class BinaryInstaller
{
    private const REQUIRED_VERSION = '1.5.0';
    private const RELEASES_API = 'https://api.github.com/repos/php/frankenphp/releases/latest';

    public function __construct(private string $basePath) {}

    /** Whether upstream publishes a static binary for this platform. */
    public function isSupportedPlatform(): bool
    {
        return in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true);
    }

    /** Where the downloaded binary is placed (the Octane convention: root). */
    public function targetPath(): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . 'frankenphp';
    }

    public function requiredVersion(): string
    {
        return self::REQUIRED_VERSION;
    }

    /**
     * Download the latest static binary for this OS/arch into the project root.
     *
     * @param  callable|null  $onProgress  fn(int $downloaded, int $total): void
     * @return string  The path to the downloaded binary.
     */
    public function download(?callable $onProgress = null): string
    {
        $asset = $this->assetName();
        $release = $this->fetchJson(self::RELEASES_API);

        $url = null;
        foreach ($release['assets'] ?? [] as $a) {
            if (($a['name'] ?? null) === $asset) {
                $url = $a['browser_download_url'] ?? null;
                break;
            }
        }

        if ($url === null) {
            throw new RuntimeException("FrankenPHP release asset '{$asset}' not found.");
        }

        $path = $this->targetPath();
        $this->streamTo($url, $path, $onProgress);
        @chmod($path, 0755);

        return $path;
    }

    /** Whether an installed binary meets Thrust's minimum required version. */
    public function meetsRequirements(string $binary): bool
    {
        $out = [];
        @exec(escapeshellarg($binary) . ' version 2>&1', $out);
        if (preg_match('/v?(\d+\.\d+\.\d+)/', implode("\n", $out), $m)) {
            return version_compare($m[1], self::REQUIRED_VERSION, '>=');
        }

        return true; // Version undetectable — don't block the user.
    }

    /** Octane-compatible release asset name for the current OS/arch. */
    private function assetName(): string
    {
        $arch = php_uname('m');

        return match (PHP_OS_FAMILY) {
            'Linux' => match ($arch) {
                'x86_64' => 'frankenphp-linux-x86_64' . ($this->hasGnuLibc() ? '-gnu' : ''),
                'aarch64', 'arm64' => 'frankenphp-linux-aarch64' . ($this->hasGnuLibc() ? '-gnu' : ''),
                default => throw new RuntimeException("Unsupported Linux architecture: {$arch}."),
            },
            'Darwin' => 'frankenphp-mac-' . $arch,
            default => throw new RuntimeException(
                'FrankenPHP static binaries are published only for Linux and macOS. '
                . 'On Windows, run Thrust under WSL or Docker.'
            ),
        };
    }

    private function hasGnuLibc(): bool
    {
        $out = [];
        $code = 1;
        @exec('getconf GNU_LIBC_VERSION 2>/dev/null', $out, $code);

        return $code === 0;
    }

    private function fetchJson(string $url): array
    {
        $data = json_decode($this->request($url, 30), true);
        if (! is_array($data)) {
            throw new RuntimeException('Failed to parse the GitHub releases response.');
        }

        return $data;
    }

    private function request(string $url, int $timeout): string
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'follow_location' => 1,
            'header' => "User-Agent: NitroThrust\r\nAccept: application/vnd.github+json\r\n",
        ]]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException("Failed to fetch {$url}.");
        }

        return $body;
    }

    private function streamTo(string $url, string $path, ?callable $onProgress): void
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => 300,
            'follow_location' => 1,
            'header' => "User-Agent: NitroThrust\r\n",
        ]]);

        $in = @fopen($url, 'rb', false, $context);
        if ($in === false) {
            throw new RuntimeException("Failed to download {$url}.");
        }

        $total = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (stripos($header, 'Content-Length:') === 0) {
                $total = (int) trim(substr($header, 15));
            }
        }

        $out = fopen($path, 'wb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException("Failed to open {$path} for writing.");
        }

        $downloaded = 0;
        while (! feof($in)) {
            $chunk = fread($in, 1 << 16);
            if ($chunk === false) {
                break;
            }
            fwrite($out, $chunk);
            $downloaded += strlen($chunk);
            if ($onProgress !== null) {
                $onProgress($downloaded, $total);
            }
        }

        fclose($in);
        fclose($out);
    }
}
