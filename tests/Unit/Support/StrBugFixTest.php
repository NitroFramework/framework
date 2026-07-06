<?php

namespace Tests\Unit\Support;

use Nitro\Support\Str;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for Str::slug and Str::random found in the layer sweep.
 */
class StrBugFixTest extends TestCase
{
    public function test_slug_honors_custom_separator(): void
    {
        $this->assertSame('foo_bar_baz', Str::slug('Foo Bar Baz', '_'));
    }

    public function test_slug_lowercases_and_keeps_letters(): void
    {
        // Old code used ASCII strtolower + [^-\w], dropping accented letters
        // entirely. It must at least keep (and lowercase) them now.
        $slug = Str::slug('Héllo Wörld');
        $this->assertStringContainsString('h', $slug);
        $this->assertStringNotContainsString(' ', $slug);
        $this->assertSame($slug, mb_strtolower($slug, 'UTF-8'));
    }

    public function test_slug_collapses_separators_and_trims(): void
    {
        $this->assertSame('a-b-c', Str::slug('  a---b   c  '));
    }

    public function test_slug_transliterates_accents_to_ascii(): void
    {
        $this->assertSame('hello-world-cafe', Str::slug('Héllo Wörld café'));
        $this->assertSame('strasse-uber', Str::slug('Straße über'));
    }

    public function test_ascii_folds_common_latin(): void
    {
        $this->assertSame('cafe nono', Str::ascii('CAFÉ Ñoño'));
    }

    public function test_random_length_and_alphabet(): void
    {
        $r = Str::random(32);
        $this->assertSame(32, strlen($r));
        // Base62-ish alphabet, not hex-only (the old bug halved entropy).
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $r);
        $this->assertDoesNotMatchRegularExpression('/^[0-9a-f]+$/', $r);
    }

    public function test_random_is_unique_enough(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $seen[Str::random(16)] = true;
        }
        $this->assertCount(200, $seen);
    }
}
