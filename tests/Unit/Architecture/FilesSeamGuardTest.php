<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Architectural guard (sibling of SessionSeamGuardTest): uploaded files flow
 * through the Request seam — `app('request')->allFiles()` / `request()->allFiles()`
 * — never the raw $_FILES superglobal. Reaching around the abstraction is the
 * same class of leak that hides worker-mode/testability problems. New direct
 * usage fails this test: route it through the Request instead, or (if it's the
 * Request implementation itself or a genuine CLI fallback) add it to the
 * allowlist. Token-based, so $_FILES in comments/strings is ignored.
 */
class FilesSeamGuardTest extends TestCase
{
    /** Files permitted to touch raw $_FILES, each for a documented reason. */
    private const ALLOWLIST = [
        'src/Http/Request.php',            // the Request abstraction captures $_FILES
        'src/Support/Helpers/request.php', // files() helper — CLI/no-request fallback
    ];

    public function test_no_raw_files_superglobal_outside_the_allowlist(): void
    {
        $root = dirname(__DIR__, 3);
        $srcDir = $root . DIRECTORY_SEPARATOR . 'src';

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php' || $file->getFilename() === 'bundle.php') {
                continue;
            }

            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (in_array($rel, self::ALLOWLIST, true)) {
                continue;
            }

            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$_FILES') {
                    $offenders[] = $rel;
                    continue 2;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Raw \$_FILES found outside the Request seam — use app('request')->allFiles()"
            . " instead (or allowlist it if it's the Request impl / a genuine fallback):\n  "
            . implode("\n  ", $offenders)
        );
    }
}
