<?php

namespace Tests\Unit\View;

use Nitro\View\Support\HtmlString;
use PHPUnit\Framework\TestCase;

enum EscapeStatus: string
{
    case Danger = '<b>x</b>';
}

/**
 * nitro_e() is what compiled {{ }} echoes call. It must match Laravel's e():
 * ENT_QUOTES | ENT_SUBSTITUTE, double-encoding on, Htmlable passthrough,
 * BackedEnum → value, null → ''.
 */
class EscapingTest extends TestCase
{
    public function test_escapes_html_tags(): void
    {
        $this->assertSame('&lt;script&gt;', nitro_e('<script>'));
    }

    public function test_escapes_both_quote_styles(): void
    {
        $this->assertSame('&quot;', nitro_e('"'));
        $this->assertSame('&#039;', nitro_e("'"));
    }

    public function test_double_encodes_existing_entities(): void
    {
        // Laravel double-encodes by default; the old nitro_e passed false and
        // left '&lt;' untouched, diverging from e()/escape().
        $this->assertSame('&amp;lt;', nitro_e('&lt;'));
    }

    public function test_null_becomes_empty_string(): void
    {
        $this->assertSame('', nitro_e(null));
    }

    public function test_backed_enum_renders_its_escaped_value(): void
    {
        // Previously (string) on a BackedEnum threw a fatal error.
        $this->assertSame('&lt;b&gt;x&lt;/b&gt;', nitro_e(EscapeStatus::Danger));
    }

    public function test_htmlable_passes_through_unescaped(): void
    {
        $this->assertSame('<b>raw</b>', nitro_e(new HtmlString('<b>raw</b>')));
    }

    public function test_invalid_utf8_does_not_vanish(): void
    {
        // ENT_SUBSTITUTE: malformed UTF-8 becomes the replacement char instead
        // of htmlspecialchars returning an empty string.
        $this->assertNotSame('', nitro_e("\xB1\x31"));
    }
}
