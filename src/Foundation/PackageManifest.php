<?php

namespace Nitro\Foundation;

/**
 * Discover the service providers and aliases installed packages declare.
 *
 * A package declares them under extra.nitro in its composer.json:
 *
 *   "extra": { "nitro": { "providers": ["Vendor\\Pkg\\PkgServiceProvider"] } }
 *
 * An application opts a package out with extra.nitro.dont-discover in its own
 * composer.json, and "*" there disables discovery. The map is cached to
 * packages.php and rebuilt by `nitro package:discover`.
 */
class PackageManifest
{
    /** @var array<string, array<string, mixed>>|null */
    protected ?array $manifest = null;

    /**
     * @param string $vendorPath   Absolute path to the application's vendor directory.
     * @param string $basePath     Absolute project root, holding composer.json.
     * @param string $manifestPath Absolute path to the cached packages.php.
     */
    public function __construct(
        protected string $vendorPath,
        protected string $basePath,
        protected string $manifestPath,
    ) {}

    /**
     * Get the discovered provider classes, skipping any that cannot be autoloaded.
     *
     * @return array<int, class-string>
     */
    public function providers(): array
    {
        return array_values(array_filter(
            $this->config('providers'),
            static fn ($providerClass): bool => is_string($providerClass) && class_exists($providerClass)
        ));
    }

    /**
     * Get the discovered aliases.
     *
     * @return array<string, class-string>
     */
    public function aliases(): array
    {
        return $this->config('aliases');
    }

    /**
     * Get one key, such as providers or aliases, across every discovered package.
     *
     * @return array<int|string, mixed>
     */
    public function config(string $key): array
    {
        $result = [];

        foreach ($this->getManifest() as $configuration) {
            foreach ((array) ($configuration[$key] ?? []) as $entryKey => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                is_int($entryKey) ? $result[] = $value : $result[$entryKey] = $value;
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
     * Read installed.json, drop the ignored packages and write the manifest cache.
     */
    public function build(): void
    {
        $packages = [];
        $installedJson = $this->vendorPath . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.json';

        if (is_file($installedJson)) {
            $installed = json_decode((string) file_get_contents($installedJson), true) ?: [];
            $packages = $installed['packages'] ?? $installed;
        }

        $ignore = $this->packagesToIgnore();

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

    /** Get the packages the application's composer.json opts out of discovery. */
    protected function packagesToIgnore(): array
    {
        $composer = $this->basePath . DIRECTORY_SEPARATOR . 'composer.json';
        if (! is_file($composer)) {
            return [];
        }

        $json = json_decode((string) file_get_contents($composer), true) ?: [];

        return (array) ($json['extra']['nitro']['dont-discover'] ?? []);
    }

    /** Write the manifest cache. */
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
