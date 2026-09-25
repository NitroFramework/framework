<?php

namespace Nitro\Foundation;

use Throwable;

/**
 * Record once which providers register eagerly and which defer.
 *
 * Rebuilt whenever the provider list changes, so nothing has to be cleared by hand.
 */
final class ProviderManifest
{
    /**
     * @param string $path Where the manifest is written.
     */
    public function __construct(private readonly string $path) {}

    /**
     * Get the eager providers, the deferred service map and the events that wake a provider.
     *
     * @param  array<int, class-string> $providers
     * @return array{0: array<int, class-string>, 1: array<string, class-string>, 2: array<class-string, array<int, string>>}
     */
    public function resolve(array $providers, object $container): array
    {
        $manifest = $this->read();

        if ($manifest !== null && $manifest['providers'] === $providers) {
            return [$manifest['eager'], $manifest['deferred'], $manifest['when']];
        }

        [$eager, $deferred, $when] = static::split($providers, $container);

        $this->write($providers, $eager, $deferred, $when);

        return [$eager, $deferred, $when];
    }

    /**
     * Sort providers into those that register now and those that wait.
     *
     * A provider that cannot be constructed counts as eager, so it fails at registration.
     *
     * @param  array<int, class-string> $providers
     * @return array{0: array<int, class-string>, 1: array<string, class-string>, 2: array<class-string, array<int, string>>}
     */
    public static function split(array $providers, object $container): array
    {
        $eager = [];
        $deferred = [];
        $when = [];

        foreach ($providers as $providerClass) {
            try {
                $instance = new $providerClass($container);

                if (! $instance->isDeferred()) {
                    $eager[] = $providerClass;
                    continue;
                }

                foreach ($instance->provides() as $service) {
                    $deferred[$service] = $providerClass;
                }

                if ($events = $instance->when()) {
                    $when[$providerClass] = $events;
                }
            } catch (Throwable) {
                $eager[] = $providerClass;
            }
        }

        return [$eager, $deferred, $when];
    }

    /**
     * Read the manifest from disk, or null when there is none to trust.
     *
     * @return array{providers: array<int, string>, eager: array<int, string>, deferred: array<string, string>, when: array<string, array<int, string>>}|null
     */
    private function read(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        $manifest = @include $this->path;

        if (! is_array($manifest)
            || ! isset($manifest['providers'], $manifest['eager'], $manifest['deferred'])) {
            return null;
        }

        $manifest['when'] ??= [];

        return $manifest;
    }

    /**
     * Write the manifest atomically, through a temporary file moved into place.
     *
     * @param array<int, class-string>                  $providers
     * @param array<int, class-string>                  $eager
     * @param array<string, class-string>               $deferred
     * @param array<class-string, array<int, string>>   $when
     */
    private function write(array $providers, array $eager, array $deferred, array $when): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return;
        }

        $contents = "<?php\n\nreturn " . var_export(compact('providers', 'eager', 'deferred', 'when'), true) . ";\n";

        $temporary = $this->path . '.' . getmypid();

        if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
            return;
        }

        if (! @rename($temporary, $this->path)) {
            @unlink($temporary);
        }
    }
}
