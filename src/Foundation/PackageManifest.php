<?php

namespace Nitro\Foundation;

/**
 * Laravel-style package auto-discovery. Installed Composer packages opt in via
 * an `extra.nitro` block in their composer.json, and their service providers
 * (and aliases) register automatically on `composer require` — no manual wiring:
 *
 *   "extra": { "nitro": { "providers": ["Vendor\\Pkg\\PkgServiceProvider"] } }
 *
 * The discovered map is read from vendor/composer/installed.json and cached to
 * packages.php. The cache is rebuilt by `nitro package:discover`, wired into the
 * app's composer `post-autoload-dump` — so, exactly like Laravel, it is
 * regenerated on every install/update and never silently goes stale. (It is NOT
 * an `optimize` cache; it does not freeze under APP_DEBUG=false.) Works the same
 * under FPM and under Thrust/worker mode.
 *
 * An app can opt a package out with `extra.nitro.dont-discover` in its own
 * composer.json ("*" disables discovery entirely); a package may likewise list
 * others to ignore.
 */
class PackageManifest
{
    /** @var array<string, array<string, mixed>>|null */
    protected ?array $manifest = null;

    /**
     * @param string $vendorPath   Absolute path to the app's vendor/ directory.
     * @param string $basePath     Absolute project root (holds composer.json).
     * @param string $manifestPath Absolute path to the cached packages.php.
     */
    public function __construct(
        protected string $vendorPath,
        protected string $basePath,
        protected string $manifestPath,
    ) {}

    /** @return array<int, class-string> */
    public function providers(): array
    {
        // Non-fatal: a declared provider that isn't autoloadable is skipped
        // rather than crashing the whole boot.
        return array_values(array_filter(
            $this->config('providers'),
            static fn ($p): bool => is_string($p) && class_exists($p)
        ));
    }

    /** @return array<string, class-string> */
    public function aliases(): array
    {
        return $this->config('aliases');
    }

    /**
     * Flatten one key (providers/aliases) across every discovered package.
     *
     * @return array<int|string, mixed>
     */
    public function config(string $key): array
    {
        $result = [];

        foreach ($this->getManifest() as $configuration) {
            foreach ((array) ($configuration[$key] ?? []) as $k => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                is_int($k) ? $result[] = $value : $result[$k] = $value;
            }
        }

        return $result;
    }

    /** Load the cached manifest, building it on first use. */
    protected function getManifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        if (! is_file($this->manifestPath)) {
            $this->build();
        }

        return $this->manifest = is_file($this->manifestPath)
            ? (array) require $this->manifestPath
            : [];
    }

    /**
     * Read installed.json, apply dont-discover, and write the extra.nitro map to
     * the packages.php cache. Called lazily and by `nitro package:discover`.
     */
    public function build(): void
    {
        $packages = [];
        $installedJson = $this->vendorPath . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.json';

        if (is_file($installedJson)) {
            $installed = json_decode((string) file_get_contents($installedJson), true) ?: [];
            // Composer 2 nests under "packages"; tolerate a flat list.
            $packages = $installed['packages'] ?? $installed;
        }

        $ignore = $this->packagesToIgnore();

        // First pass: collect each package's extra.nitro block; a package may
        // contribute its own dont-discover entries.
        $manifest = [];
        foreach ($packages as $package) {
            $name = $package['name'] ?? null;
            if (! is_string($name)) {
                continue;
            }
            $extra = $package['extra']['nitro'] ?? [];
            $ignore = array_merge($ignore, (array) ($extra['dont-discover'] ?? []));
            if (! empty($extra)) {
                $manifest[$name] = $extra;
            }
        }

        // Second pass: drop ignored packages ("*" disables discovery entirely).
        if (in_array('*', $ignore, true)) {
            $manifest = [];
        } else {
            foreach ($ignore as $name) {
                unset($manifest[$name]);
            }
        }

        $this->write($manifest);
        $this->manifest = $manifest;
    }

    /** App-level opt-outs from the project's own composer.json. */
    protected function packagesToIgnore(): array
    {
        $composer = $this->basePath . DIRECTORY_SEPARATOR . 'composer.json';
        if (! is_file($composer)) {
            return [];
        }

        $json = json_decode((string) file_get_contents($composer), true) ?: [];

        return (array) ($json['extra']['nitro']['dont-discover'] ?? []);
    }

    protected function write(array $manifest): void
    {
        $dir = dirname($this->manifestPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->manifestPath,
            '<?php return ' . var_export($manifest, true) . ';' . PHP_EOL
        );
    }
}
