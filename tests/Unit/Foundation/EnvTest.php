<?php

namespace Tests\Unit\Foundation;

use Nitro\Foundation\Env;
use PHPUnit\Framework\TestCase;

/**
 * The .env grammar.
 *
 * parse() is pure, so everything about the grammar is tested without touching
 * the process environment; load() is exercised separately with a temp file.
 */
class EnvTest extends TestCase
{
    /** @return array<string, string> */
    private function parse(string $contents): array
    {
        return Env::parse($contents);
    }

    // ─── Basics ───────────────────────────────────────────

    public function test_simple_assignment(): void
    {
        $this->assertSame(['APP_NAME' => 'Nitro'], $this->parse('APP_NAME=Nitro'));
    }

    public function test_empty_value(): void
    {
        $this->assertSame(['EMPTY' => ''], $this->parse('EMPTY='));
    }

    public function test_export_prefix_is_ignored(): void
    {
        $this->assertSame(['A' => '1'], $this->parse('export A=1'));
    }

    public function test_blank_lines_and_comments(): void
    {
        $contents = <<<ENV
        # a comment

        A=1
            # an indented comment
        B=2
        ENV;

        $this->assertSame(['A' => '1', 'B' => '2'], $this->parse($contents));
    }

    public function test_whitespace_around_the_separator(): void
    {
        $this->assertSame(['A' => '1'], $this->parse('A = 1'));
    }

    public function test_line_endings(): void
    {
        $this->assertSame(['A' => '1', 'B' => '2'], $this->parse("A=1\r\nB=2"));
        $this->assertSame(['A' => '1', 'B' => '2'], $this->parse("A=1\rB=2"));
    }

    // ─── Keys ─────────────────────────────────────────────

    public function test_invalid_keys_are_skipped(): void
    {
        $this->assertSame([], $this->parse('9LEADING_DIGIT=x'));
        $this->assertSame([], $this->parse('HAS-DASH=x'));
        $this->assertSame([], $this->parse('no equals sign here'));
    }

    public function test_dotted_keys_are_allowed(): void
    {
        $this->assertSame(['MAIL.HOST' => 'localhost'], $this->parse('MAIL.HOST=localhost'));
    }

    // ─── Comments on values ───────────────────────────────

    public function test_inline_comment_is_stripped(): void
    {
        $this->assertSame(['A' => '1'], $this->parse('A=1 # trailing note'));
    }

    /** A # without preceding whitespace is part of the value. */
    public function test_hash_inside_a_value_is_kept(): void
    {
        $this->assertSame(['COLOR' => '#ff0000'], $this->parse('COLOR=#ff0000'));
        $this->assertSame(['URL' => 'http://a/b#frag'], $this->parse('URL=http://a/b#frag'));
    }

    public function test_hash_inside_a_quoted_value_is_kept(): void
    {
        $this->assertSame(['A' => 'x # y'], $this->parse('A="x # y"'));
    }

    // ─── Quoting ──────────────────────────────────────────

    public function test_double_quotes_are_removed(): void
    {
        $this->assertSame(['A' => 'hello world'], $this->parse('A="hello world"'));
    }

    public function test_single_quotes_are_removed(): void
    {
        $this->assertSame(['A' => 'hello world'], $this->parse("A='hello world'"));
    }

    public function test_escapes_resolve_in_double_quotes(): void
    {
        $this->assertSame(['A' => "line1\nline2"], $this->parse('A="line1\nline2"'));
        $this->assertSame(['A' => "a\tb"], $this->parse('A="a\tb"'));
        $this->assertSame(['A' => 'say "hi"'], $this->parse('A="say \"hi\""'));
        $this->assertSame(['A' => 'back\\slash'], $this->parse('A="back\\\\slash"'));
    }

    /** Single quotes are literal — an escape sequence stays as written. */
    public function test_escapes_are_literal_in_single_quotes(): void
    {
        $this->assertSame(['A' => 'line1\nline2'], $this->parse("A='line1\\nline2'"));
    }

    public function test_escaped_single_quote(): void
    {
        $this->assertSame(['A' => "it's"], $this->parse("A='it\\'s'"));
    }

    public function test_quoted_value_may_span_lines(): void
    {
        $contents = "KEY=\"first\nsecond\"\nOTHER=1";

        $this->assertSame(
            ['KEY' => "first\nsecond", 'OTHER' => '1'],
            $this->parse($contents)
        );
    }

    public function test_unterminated_quote_takes_the_rest(): void
    {
        $this->assertSame(['A' => 'unterminated'], $this->parse('A="unterminated'));
    }

    // ─── Interpolation ────────────────────────────────────

    public function test_interpolation_from_an_earlier_line(): void
    {
        $contents = "HOST=example.test\nURL=https://\${HOST}/path";

        $this->assertSame(
            ['HOST' => 'example.test', 'URL' => 'https://example.test/path'],
            $this->parse($contents)
        );
    }

    public function test_interpolation_inside_double_quotes(): void
    {
        $contents = "NAME=world\nGREETING=\"hello \${NAME}\"";

        $this->assertSame('hello world', $this->parse($contents)['GREETING']);
    }

    /** Single quotes are literal, so a placeholder is left alone. */
    public function test_no_interpolation_inside_single_quotes(): void
    {
        $contents = "NAME=world\nGREETING='hello \${NAME}'";

        $this->assertSame('hello ${NAME}', $this->parse($contents)['GREETING']);
    }

    public function test_unknown_placeholder_becomes_empty(): void
    {
        $this->assertSame(['A' => 'x/'], $this->parse('A=x/${NOPE}'));
    }

    /** A backslash before the $ keeps the placeholder literal. */
    public function test_escaped_dollar_is_not_interpolated(): void
    {
        $contents = "NAME=world\nA=\"\\\${NAME}\"";

        $this->assertSame('${NAME}', $this->parse($contents)['A']);
    }

    public function test_a_lone_dollar_is_left_alone(): void
    {
        $this->assertSame('$100', $this->parse('A="$100"')['A']);
        $this->assertSame('$100', $this->parse('A="\$100"')['A']);
    }

    // ─── load() ───────────────────────────────────────────

    public function test_load_writes_to_the_environment(): void
    {
        $directory = $this->tempDirectory("NITRO_TEST_LOADED=yes\n");

        try {
            Env::load($directory);

            $this->assertSame('yes', $_ENV['NITRO_TEST_LOADED']);
            $this->assertSame('yes', $_SERVER['NITRO_TEST_LOADED']);
            $this->assertSame('yes', getenv('NITRO_TEST_LOADED'));
        } finally {
            $this->forget('NITRO_TEST_LOADED');
            $this->removeDirectory($directory);
        }
    }

    /** The platform's own variables outrank the file. */
    public function test_load_does_not_overwrite_by_default(): void
    {
        $_ENV['NITRO_TEST_EXISTING'] = 'from-platform';
        putenv('NITRO_TEST_EXISTING=from-platform');

        $directory = $this->tempDirectory("NITRO_TEST_EXISTING=from-file\n");

        try {
            Env::load($directory);

            $this->assertSame('from-platform', $_ENV['NITRO_TEST_EXISTING']);
        } finally {
            $this->forget('NITRO_TEST_EXISTING');
            $this->removeDirectory($directory);
        }
    }

    public function test_load_overwrites_when_asked(): void
    {
        $_ENV['NITRO_TEST_FORCED'] = 'old';
        putenv('NITRO_TEST_FORCED=old');

        $directory = $this->tempDirectory("NITRO_TEST_FORCED=new\n");

        try {
            Env::load($directory, '.env', true);

            $this->assertSame('new', $_ENV['NITRO_TEST_FORCED']);
        } finally {
            $this->forget('NITRO_TEST_FORCED');
            $this->removeDirectory($directory);
        }
    }

    /** An application may be configured entirely from real env vars. */
    public function test_missing_file_is_not_an_error(): void
    {
        $this->assertSame([], Env::load(sys_get_temp_dir() . '/nitro_no_such_dir_' . uniqid()));
    }

    // ─── Helpers ──────────────────────────────────────────

    private function tempDirectory(string $contents): string
    {
        $directory = sys_get_temp_dir() . '/nitro_env_' . bin2hex(random_bytes(6));
        mkdir($directory);
        file_put_contents($directory . '/.env', $contents);

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        @unlink($directory . '/.env');
        @rmdir($directory);
    }

    private function forget(string $key): void
    {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }
}
