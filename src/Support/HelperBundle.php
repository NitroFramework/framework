<?php

namespace Nitro\Support;

/**
 * Builds src/Support/helpers.php: every helper file written out into one.
 *
 * Composer includes each autoload file on every request, so twenty-six helper
 * files meant twenty-six file opens before the application ran. The bundle is
 * one. It is committed, and it holds the functions themselves rather than
 * requiring the sources, so an editor or static analyser reading it sees every
 * helper — the reason an earlier runtime bundle was dropped.
 *
 * The files in Helpers/ stay the source to edit; `composer helpers` rewrites
 * the bundle, and HelperBundleTest fails while the two disagree.
 *
 * Never loaded on a request: only the composer script and the test use it.
 */
final class HelperBundle
{
    /**
     * The helper files, in the order they are written into the bundle.
     *
     * The order the autoload list had, so a helper defined in terms of another
     * still finds it declared first. The profiling switch leads, because the
     * first profiling mark is taken before anything else runs.
     */
    public const FILES = [
        'profile', 'app', 'config', 'path', 'array', 'collection', 'conditional', 'debug',
        'file', 'http', 'request', 'response', 'security', 'auth', 'session',
        'string', 'url', 'utility', 'validation', 'view', 'query', 'cache',
        'cookie', 'translation', 'inertia', 'event', 'trace',
    ];

    /** The bundle, as it should read for the helper files on disk now. */
    public static function build(?string $helpersDirectory = null): string
    {
        $directory = $helpersDirectory ?? __DIR__ . '/Helpers';

        $bundle = "<?php\n\n"
            . "// Generated from src/Support/Helpers/ by `composer helpers`. Do not edit:\n"
            . "// change the file there and regenerate. Each file keeps its own namespace\n"
            . "// block, so the classes one imports cannot clash with another's.\n";

        foreach (self::FILES as $name) {
            $source = file_get_contents("{$directory}/{$name}.php");

            $body = trim(preg_replace('/^<\?php\s*/', '', $source, 1));

            $bundle .= "\n// ── {$name}.php " . str_repeat('─', max(3, 60 - strlen($name))) . "\n\n"
                . "namespace {\n\n{$body}\n\n}\n";
        }

        return $bundle;
    }

    /** Rewrite the bundle. Run as `composer helpers`. */
    public static function write(mixed $event = null): void
    {
        file_put_contents(__DIR__ . '/helpers.php', self::build());
    }
}
