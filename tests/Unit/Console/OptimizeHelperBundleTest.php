<?php

namespace Tests\Unit\Console;

use PHPUnit\Framework\TestCase;

/**
 * `nitro optimize` bundles Support/Helpers/*.php into a single file. It derives
 * the file list (and order) from Support/helpers.php so the bundle can never
 * drift from the runtime loader. Regression: a hardcoded list once omitted
 * cookie.php, leaving cookie() undefined in optimized mode.
 */
class OptimizeHelperBundleTest extends TestCase
{
    private function derivedBundleList(): array
    {
        $loader = file_get_contents(dirname(__DIR__, 3) . '/src/Support/helpers.php');
        preg_match_all('#/Helpers/([A-Za-z0-9_]+\.php)#', (string) $loader, $m);

        $files = [];
        foreach ($m[1] as $f) {
            if ($f !== 'bundle.php' && !in_array($f, $files, true)) {
                $files[] = $f;
            }
        }
        return $files;
    }

    public function test_loader_list_includes_cookie(): void
    {
        // The bundle derives from this list, so guarding the loader guards both.
        $this->assertContains('cookie.php', $this->derivedBundleList());
    }

    public function test_every_bundled_helper_file_exists(): void
    {
        $dir = dirname(__DIR__, 3) . '/src/Support/Helpers/';
        foreach ($this->derivedBundleList() as $f) {
            $this->assertFileExists($dir . $f, "Helper {$f} referenced by the loader must exist");
        }
    }

    public function test_bundle_php_is_excluded_from_its_own_source_list(): void
    {
        $this->assertNotContains('bundle.php', $this->derivedBundleList());
    }
}
