<?php

namespace Tests\Unit\Architecture;

use Nitro\Support\HelperBundle;
use PHPUnit\Framework\TestCase;

/**
 * The helpers reach an application as one committed bundle.
 *
 * Composer includes every autoload file on every request, so the twenty-six
 * helper files cost twenty-six file opens each time. The bundle is one, and
 * because it holds the functions themselves rather than requiring the sources,
 * an editor or static analyser reading it still sees every helper — which a
 * runtime loader that required the others did not.
 *
 * The files in src/Support/Helpers/ remain what gets edited. These tests make
 * forgetting to regenerate, or adding a file without bundling it, fail here.
 */
class HelperAutoloadTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<int, string> */
    private function listedFiles(): array
    {
        $composer = json_decode(file_get_contents($this->root() . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        return $composer['autoload']['files'] ?? [];
    }

    /** @return array<int, string> */
    private function helperFiles(): array
    {
        return array_map(
            static fn (string $path): string => basename($path, '.php'),
            glob($this->root() . '/src/Support/Helpers/*.php') ?: [],
        );
    }

    public function test_composer_loads_the_bundle_alone(): void
    {
        $this->assertSame(['src/Support/helpers.php'], $this->listedFiles());
    }

    /** Edited a helper and forgot `composer helpers`: this is where it shows. */
    public function test_the_bundle_matches_the_helper_files(): void
    {
        $this->assertSame(
            HelperBundle::build(),
            file_get_contents($this->root() . '/src/Support/helpers.php'),
            'src/Support/helpers.php is out of date. Run `composer helpers`.'
        );
    }

    public function test_every_helper_file_is_bundled(): void
    {
        foreach ($this->helperFiles() as $name) {
            $this->assertContains(
                $name,
                HelperBundle::FILES,
                "src/Support/Helpers/{$name}.php is not in HelperBundle::FILES, so it never loads."
            );
        }
    }

    public function test_every_bundled_file_exists(): void
    {
        foreach (HelperBundle::FILES as $name) {
            $this->assertFileExists($this->root() . "/src/Support/Helpers/{$name}.php");
        }
    }

    /** The bundle declares the functions itself, which is what an editor reads. */
    public function test_the_bundle_declares_the_functions_rather_than_requiring_files(): void
    {
        $bundle = file_get_contents($this->root() . '/src/Support/helpers.php');

        $this->assertDoesNotMatchRegularExpression('/^\s*(require|include)(_once)?\s/m', $bundle);
        $this->assertStringContainsString("function view(", $bundle);
    }

    /**
     * Guards against two files declaring the same function, where which one
     * wins depends on order.
     */
    public function test_no_helper_is_declared_twice(): void
    {
        $seen = [];

        foreach ($this->helperFiles() as $name) {
            preg_match_all(
                '/^\s{4}function\s+(\w+)\s*\(/m',
                file_get_contents($this->root() . "/src/Support/Helpers/{$name}.php"),
                $matches
            );

            foreach ($matches[1] as $function) {
                $this->assertArrayNotHasKey($function, $seen, "{$function}() is declared in both {$name}.php and " . ($seen[$function] ?? '?') . '.php.');

                $seen[$function] = $name;
            }
        }

        $this->assertNotEmpty($seen, 'No helpers found at all, which cannot be right.');
    }
}
