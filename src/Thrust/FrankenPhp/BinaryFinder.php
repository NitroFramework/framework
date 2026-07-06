<?php

namespace Nitro\Thrust\FrankenPhp;

/**
 * Locates the FrankenPHP binary, following Laravel Octane's convention: a single
 * binary on the system PATH or at the project root (where thrust:install
 * downloads it) — never a bundled bin/ directory. It also checks ~/.frankenphp,
 * where the official frankenphp.dev installer drops the binary, so a fresh
 * install is found even before its PATH entry has propagated to the shell.
 * Returns the absolute path, or null when no binary can be found.
 */
class BinaryFinder
{
    public function __construct(private string $basePath) {}

    /** The absolute path to the frankenphp binary, or null if not installed. */
    public function find(): ?string
    {
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $names = $isWindows ? ['frankenphp.exe', 'frankenphp'] : ['frankenphp'];

        // 1) Known install directories, checked on disk (independent of PATH).
        foreach ($this->searchDirs() as $dir) {
            foreach ($names as $name) {
                $candidate = $dir . DIRECTORY_SEPARATOR . $name;
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        // 2) Anywhere on PATH.
        return $this->findOnPath($isWindows, $names);
    }

    /**
     * Directories to probe for the binary: the project root (Octane's
     * single-binary convention) and ~/.frankenphp (the official installer's
     * home).
     *
     * @return list<string>
     */
    private function searchDirs(): array
    {
        $dirs = [$this->basePath];

        $home = getenv('HOME') ?: getenv('USERPROFILE');
        if ($home !== false && $home !== '') {
            $dirs[] = $home . DIRECTORY_SEPARATOR . '.frankenphp';
        }

        return $dirs;
    }

    private function findOnPath(bool $isWindows, array $names): ?string
    {
        $which = $isWindows ? 'where' : 'command -v';
        $null = $isWindows ? 'NUL' : '/dev/null';

        foreach ($names as $name) {
            $output = [];
            $status = 1;
            exec("{$which} " . escapeshellarg($name) . " 2>{$null}", $output, $status);
            if ($status === 0 && !empty($output[0])) {
                return trim($output[0]);
            }
        }

        return null;
    }
}
