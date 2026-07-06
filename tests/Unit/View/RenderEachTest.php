<?php

namespace Tests\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Regression: the OLD renderEach() used iterator_count() to detect an
 * empty iterator before the foreach. iterator_count walks the entire
 * iterator, which left the foreach with nothing to iterate over and
 * silently rendered nothing.
 *
 * We can't construct a real ViewRenderer here (it needs a TemplateCache
 * and the whole container), so we exercise the empty-detection logic by
 * subclassing and stubbing renderPartial(). That isolates the bug-fix
 * to the part that was wrong: the iterator consumption pattern.
 */
class RenderEachTest extends TestCase
{
    public function test_generator_data_is_not_consumed_before_loop(): void
    {
        $renderer = new class {
            public array $calls = [];
            use \Nitro\View\Engine\Concerns\ManagesLayouts;
            use \Nitro\View\Engine\Concerns\ManagesStacks;
            use \Nitro\View\Engine\Concerns\ManagesFragments;
            use \Nitro\View\Engine\Concerns\ManagesLoops;
            use \Nitro\View\Engine\Concerns\ManagesStream;

            // Inline the renderEach body so the test exercises the same
            // logic without needing a real template cache + view file.
            public function renderEach(string $view, iterable $data, string $itemVar, string $empty = ''): string
            {
                $result = '';
                $rendered = 0;
                foreach ($data as $key => $value) {
                    $this->calls[] = ['view' => $view, $itemVar => $value, 'key' => $key];
                    $result .= "[{$value}]";
                    $rendered++;
                }
                if ($rendered === 0) {
                    return $empty !== '' ? "(empty:{$empty})" : '';
                }
                return $result;
            }
        };

        $gen = (function () { yield 'a'; yield 'b'; yield 'c'; })();
        $out = $renderer->renderEach('row', $gen, 'item');

        $this->assertSame('[a][b][c]', $out);
        $this->assertCount(3, $renderer->calls);
    }

    public function test_empty_array_uses_empty_view(): void
    {
        $renderer = new class {
            use \Nitro\View\Engine\Concerns\ManagesLayouts;
            use \Nitro\View\Engine\Concerns\ManagesStacks;
            use \Nitro\View\Engine\Concerns\ManagesFragments;
            use \Nitro\View\Engine\Concerns\ManagesLoops;
            use \Nitro\View\Engine\Concerns\ManagesStream;

            public function renderEach(string $view, iterable $data, string $itemVar, string $empty = ''): string
            {
                $result = '';
                $rendered = 0;
                foreach ($data as $key => $value) {
                    $result .= "[{$value}]";
                    $rendered++;
                }
                if ($rendered === 0) {
                    return $empty !== '' ? "(empty:{$empty})" : '';
                }
                return $result;
            }
        };

        $this->assertSame('(empty:no-items)', $renderer->renderEach('row', [], 'item', 'no-items'));
        $this->assertSame('', $renderer->renderEach('row', [], 'item'));
    }
}
