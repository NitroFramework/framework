<?php

namespace Tests\Unit\Blaze;

use Nitro\Blaze\BlazeManager;
use PHPUnit\Framework\TestCase;

/**
 * A component may open with `@php use App\Support\Money; @endphp`.
 *
 * That is ordinary Blade and compiles fine through the core engine, where a
 * view body is the top level of its own file. Blaze turns a component body into
 * a closure, and PHP allows an import only at file top level — so the optimised
 * copy was a parse error while the unoptimised one worked. An optimisation that
 * changes whether the page renders is not an optimisation.
 */
class BlazeImportHoistingTest extends TestCase
{
    /** hoistImports() is internal; reach it without booting an application. */
    private function hoist(string $body): array
    {
        $manager = new class(true, '', '') extends BlazeManager {
            public function publicHoist(string $body): array
            {
                return $this->hoistImports($body);
            }
        };

        return $manager->publicHoist($body);
    }

    /** Rebuild what compile() writes, so the assertion is about a real file. */
    private function assertGeneratedFileParses(string $body): string
    {
        [$imports, $stripped] = $this->hoist($body);

        $php = "<?php\n\n" . $imports
            . "return function (array \$__data) {\n"
            . "    extract(\$__data, EXTR_SKIP);\n"
            . "    ob_start();\n"
            . "    try {\n"
            . "?>" . $stripped . "<?php\n"
            . "    } catch (\\Throwable \$e) { ob_end_clean(); throw \$e; }\n"
            . "    return ob_get_clean();\n"
            . "};\n";

        $file = tempnam(sys_get_temp_dir(), 'blaze') . '.php';
        file_put_contents($file, $php);

        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);
        @unlink($file);

        $this->assertSame(0, $status, "Generated component does not parse:\n" . implode("\n", $output));

        return $php;
    }

    public function test_an_import_moves_above_the_closure(): void
    {
        $php = $this->assertGeneratedFileParses("<?php use App\\Support\\Money; \$x = 1; ?><p>x</p>");

        $importPosition = strpos($php, 'use App\Support\Money;');
        $closurePosition = strpos($php, 'return function');

        $this->assertNotFalse($importPosition);
        $this->assertLessThan($closurePosition, $importPosition, 'the import must sit above the closure');
    }

    public function test_several_imports_including_an_alias(): void
    {
        $php = $this->assertGeneratedFileParses(
            "<?php\nuse App\\Support\\Money;\nuse App\\Support\\Basket as BasketContents;\n?><p>x</p>"
        );

        $this->assertStringContainsString('use App\Support\Money;', $php);
        $this->assertStringContainsString('use App\Support\Basket as BasketContents;', $php);
    }

    public function test_a_function_import_moves_too(): void
    {
        $php = $this->assertGeneratedFileParses("<?php use function array_map; ?><p>x</p>");

        $this->assertStringContainsString('use function array_map;', $php);
    }

    public function test_a_closure_use_clause_is_left_where_it_is(): void
    {
        // function () use ($n) is a different construct that happens to share
        // the keyword. Hoisting it would break the closure it belongs to.
        $body = "<?php \$n = 2; \$double = function (\$v) use (\$n) { return \$v * \$n; }; ?><p>x</p>";

        [$imports, $stripped] = $this->hoist($body);

        $this->assertSame('', $imports, 'a closure use clause is not an import');
        $this->assertStringContainsString('use ($n)', $stripped);

        $this->assertGeneratedFileParses($body);
    }

    public function test_a_duplicate_import_is_emitted_once(): void
    {
        // PHP refuses a repeated import in one file, and two @php blocks each
        // importing the same class is an easy thing to write.
        [$imports] = $this->hoist("<?php use App\\Support\\Money; ?><p>a</p><?php use App\\Support\\Money; ?>");

        $this->assertSame(1, substr_count($imports, 'use App\Support\Money;'));
    }

    public function test_a_body_with_no_imports_is_untouched(): void
    {
        $body = "<p>nothing to hoist</p>";

        [$imports, $stripped] = $this->hoist($body);

        $this->assertSame('', $imports);
        $this->assertSame($body, $stripped);
    }

    public function test_the_word_use_inside_content_is_not_mistaken_for_an_import(): void
    {
        $body = "<p>Courses you can use today</p>";

        [$imports, $stripped] = $this->hoist($body);

        $this->assertSame('', $imports);
        $this->assertSame($body, $stripped);
    }
}
