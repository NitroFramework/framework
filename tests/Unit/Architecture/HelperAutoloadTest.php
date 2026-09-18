<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every helper file is declared in composer.json.
 *
 * The helpers used to be pulled in by one bootstrap that required the rest at
 * runtime. PHP followed that; static analysis did not, so every call to
 * view(), session() or __() showed as an undefined function in an editor —
 * and a consumer had no way to fix it from their end.
 *
 * Naming each file in `autoload.files` is what makes them visible, so a helper
 * added later without being listed has to fail here rather than in somebody's
 * editor six months on.
 */
class HelperAutoloadTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function listedFiles(): array
    {
        $composer = json_decode(
            file_get_contents(dirname(__DIR__, 3) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        return $composer['autoload']['files'] ?? [];
    }

    /**
     * @return array<int, string>
     */
    private function filesOnDisk(): array
    {
        $paths = glob(dirname(__DIR__, 3) . '/src/Support/Helpers/*.php') ?: [];

        return array_map(
            fn (string $path): string => 'src/Support/Helpers/' . basename($path),
            $paths
        );
    }

    public function test_every_helper_file_is_listed(): void
    {
        $listed = $this->listedFiles();

        foreach ($this->filesOnDisk() as $file) {
            $this->assertContains(
                $file,
                $listed,
                "{$file} declares helpers but is not in composer.json's autoload.files, "
                    . 'so nothing will see it but PHP at runtime.'
            );
        }
    }

    public function test_every_listed_file_exists(): void
    {
        foreach ($this->listedFiles() as $file) {
            $this->assertFileExists(
                dirname(__DIR__, 3) . '/' . $file,
                "composer.json lists {$file}, which is not there."
            );
        }
    }

    /** A loader that requires the others is what hid them in the first place. */
    public function test_no_helper_file_requires_another(): void
    {
        foreach ($this->filesOnDisk() as $file) {
            $source = file_get_contents(dirname(__DIR__, 3) . '/' . $file);

            $this->assertDoesNotMatchRegularExpression(
                '/^\s*(require|include)(_once)?\s/m',
                $source,
                "{$file} pulls in another file. Each helper file must stand alone "
                    . 'and be listed in composer.json instead.'
            );
        }
    }

    /**
     * Guards against two files declaring the same function, where which one
     * wins depends on autoload order.
     */
    public function test_no_helper_is_declared_twice(): void
    {
        $seen = [];

        foreach ($this->filesOnDisk() as $file) {
            preg_match_all(
                '/^\s{4}function\s+(\w+)\s*\(/m',
                file_get_contents(dirname(__DIR__, 3) . '/' . $file),
                $matches
            );

            foreach ($matches[1] as $function) {
                $this->assertArrayNotHasKey(
                    $function,
                    $seen,
                    "{$function}() is declared in both {$file} and " . ($seen[$function] ?? '?') . '.'
                );

                $seen[$function] = $file;
            }
        }

        $this->assertNotEmpty($seen, 'No helpers found at all, which cannot be right.');
    }
}
