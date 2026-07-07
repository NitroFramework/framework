<?php

namespace Tests\Unit\Console;

use Nitro\Console\Support\ViewWarmup;
use PHPUnit\Framework\TestCase;

/**
 * The compiled-view lint used by `nitro optimize` warmup must accept compiled
 * Livewire single-file components, whose output carries top-level `use` imports.
 * The old lint wrapped content in `if (false) { … }`, making those `use`
 * statements illegal → the views were falsely reported "broken" and skipped
 * from opcache warmup. It must still reject genuinely broken PHP.
 */
class ViewWarmupLintTest extends TestCase
{
    private function lint(string $contents): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), 'nitro-view-') . '.php';
        file_put_contents($tmp, $contents);

        try {
            $warmup = (new \ReflectionClass(ViewWarmup::class))->newInstanceWithoutConstructor();
            $method = new \ReflectionMethod(ViewWarmup::class, 'compiledViewLints');
            $method->setAccessible(true);

            return (bool) $method->invoke($warmup, $tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_compiled_livewire_sfc_with_top_level_use_is_valid(): void
    {
        $contents = <<<'PHP'
        <?php
        use Nitro\Livewire\Component;
        use Nitro\Livewire\Attributes\Layout;

        new #[Layout('layouts.app')] class extends Component {
            public int $count = 0;
            public function increment(): void { $this->count++; }
        };
        ?>
        <div><?php echo $count; ?></div>
        PHP;

        $this->assertTrue(
            $this->lint($contents),
            'A compiled Livewire SFC (top-level use imports) must not be flagged broken.'
        );
    }

    public function test_genuinely_broken_php_is_rejected(): void
    {
        $this->assertFalse($this->lint('<?php $x = ;'));
    }
}
