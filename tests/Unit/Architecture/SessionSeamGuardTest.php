<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Architectural guard: session state flows through the session Store seam
 * (nitro_session() / app('session')), never the raw $_SESSION superglobal.
 *
 * Direct $_SESSION access silently breaks under FrankenPHP worker mode — there
 * is no native PHP session there, so reads come back empty and writes vanish.
 * That is exactly the class of bug that caused CSRF 419s on every worker-mode
 * POST. New direct usage fails this test: route it through nitro_session()
 * instead, or — if it is a genuine CLI/bootstrap fallback — add the file to the
 * allowlist below with a note.
 *
 * Uses token analysis (not grep) so $_SESSION in comments/strings is ignored.
 */
class SessionSeamGuardTest extends TestCase
{
    /** Files permitted to touch raw $_SESSION, each for a documented reason. */
    private const ALLOWLIST = [
        'src/Session/NativeSession.php',         // the native store IS $_SESSION
        'src/Support/Helpers/security.php',      // CLI/bootstrap fallback in csrf_token()
        'src/View/Blade.php',                    // CLI fallback in getCsrfToken()
        'src/Htmx/Support/RequestGuard.php',     // CLI fallback in verifyCsrf()
        'src/Htmx/State/SessionStateStore.php',  // CLI/test-harness fallback
        'src/Exceptions/ExceptionHandler.php',   // read-only debug dump
    ];

    public function test_no_raw_session_superglobal_outside_the_allowlist(): void
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
                if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$_SESSION') {
                    $offenders[] = $rel;
                    continue 2;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Raw \$_SESSION found outside the session Store — route it through nitro_session()"
            . " instead (or allowlist it if it's a genuine fallback):\n  " . implode("\n  ", $offenders)
        );
    }
}
