<?php

namespace Nitro\Foundation;

/**
 * Which providers are eager and which defer, answered once and written down.
 *
 * Asking a provider whether it defers means constructing it, and constructing
 * it loads its class — the whole cost deferral exists to avoid. So the question
 * is answered for every provider in one pass and the answer cached; afterwards
 * a deferred provider's class is never touched until something resolves one of
 * its services.
 *
 * The manifest records the provider list it was built from and is rebuilt when
 * that list changes, so adding a provider takes effect on the next request
 * without anything being cleared by hand.
 */
final class ProviderManifest
{
    public function __construct(private readonly string $path) {}

    /**
     * The eager providers, the deferred service map, and the events that wake.
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
     * A provider that cannot be constructed is treated as eager, so the runtime
     * fails the way it would have rather than silently dropping its services.
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
            } catch (\Throwable) {
                $eager[] = $providerClass;
            }
        }

        return [$eager, $deferred, $when];
    }

    /**
     * The manifest on disk, or null when there is none to trust.
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

        // Absent from a manifest written before when() existed, and an empty
        // map means the same thing as none: no provider waits on an event.
        $manifest['when'] ??= [];

        return $manifest;
    }

    /**
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

        // Written beside the target and moved into place, so a request reading
        // the manifest never sees a half-written file.
        $temporary = $this->path . '.' . getmypid();

        if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
            return;
        }

        if (! @rename($temporary, $this->path)) {
            @unlink($temporary);
        }
    }
}
