<?php

namespace Tests\Unit\Console;

use Nitro\Console\Commands\KeyGenerateCommand;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\PathRegistry;
use PHPUnit\Framework\TestCase;

class KeyGenerateCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nitro-key-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/.env');
        @rmdir($this->dir);
    }

    private function command(): KeyGenerateCommand
    {
        return new KeyGenerateCommand(new PathRegistry($this->dir), new OutputFormatter());
    }

    private function env(string $contents): void
    {
        file_put_contents($this->dir . '/.env', $contents);
    }

    private function readEnv(): string
    {
        return (string) file_get_contents($this->dir . '/.env');
    }

    public function test_generates_a_256_bit_base64_key_when_absent(): void
    {
        $this->env("APP_NAME=Nitro\nAPP_KEY=\n");

        $this->command()->handle('key:generate', []);

        $this->assertMatchesRegularExpression('/^APP_KEY=base64:.+$/m', $this->readEnv());

        // base64 of 32 random bytes → 44 chars (with padding).
        preg_match('/^APP_KEY=base64:(.+)$/m', $this->readEnv(), $m);
        $this->assertSame(32, strlen(base64_decode($m[1], true)));
    }

    public function test_appends_key_line_when_missing_entirely(): void
    {
        $this->env("APP_NAME=Nitro\n");

        $this->command()->handle('key:generate', []);

        $this->assertMatchesRegularExpression('/^APP_KEY=base64:.+$/m', $this->readEnv());
        $this->assertStringContainsString('APP_NAME=Nitro', $this->readEnv());
    }

    public function test_does_not_overwrite_an_existing_key_without_force(): void
    {
        $this->env("APP_KEY=base64:EXISTINGKEYVALUE=\n");

        $this->command()->handle('key:generate', []);

        $this->assertStringContainsString('APP_KEY=base64:EXISTINGKEYVALUE=', $this->readEnv());
    }

    public function test_force_overwrites_an_existing_key(): void
    {
        $this->env("APP_KEY=base64:EXISTINGKEYVALUE=\n");

        $this->command()->handle('key:generate', ['--force']);

        $this->assertStringNotContainsString('EXISTINGKEYVALUE', $this->readEnv());
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:.+$/m', $this->readEnv());
    }

    public function test_show_does_not_write_the_file(): void
    {
        $this->env("APP_KEY=\n");

        ob_start();
        $this->command()->handle('key:generate', ['--show']);
        $output = ob_get_clean();

        $this->assertStringContainsString('base64:', $output);
        // File left untouched.
        $this->assertSame("APP_KEY=\n", $this->readEnv());
    }

    public function test_two_generated_keys_differ(): void
    {
        $this->env("APP_KEY=\n");
        $this->command()->handle('key:generate', []);
        $first = $this->readEnv();

        $this->command()->handle('key:generate', ['--force']);
        $second = $this->readEnv();

        $this->assertNotSame($first, $second);
    }

    /** The APP_KEY value, whatever the file's line endings are. */
    private function key(): ?string
    {
        $lines = preg_split('/\r\n|\n|\r/', $this->readEnv()) ?: [];

        $keys = array_values(array_filter(
            $lines,
            static fn (string $line): bool => str_starts_with($line, 'APP_KEY=')
        ));

        $this->assertCount(1, $keys, 'the file must hold exactly one APP_KEY line');

        return substr($keys[0], 8);
    }

    /**
     * A .env saved through a browser has CRLF endings, because that is how
     * HTML submits a textarea — a hosting panel's environment editor produces
     * exactly this file. The empty value used to read as a one-character one,
     * so the command reported the key was already set and wrote nothing.
     */
    public function test_writes_the_key_into_a_crlf_file(): void
    {
        $this->env("APP_NAME=Nitro\r\nAPP_KEY=\r\n");

        $code = $this->command()->handle('key:generate', []);

        $this->assertSame(0, $code);
        $this->assertStringStartsWith('base64:', (string) $this->key());
    }

    public function test_a_crlf_file_keeps_its_line_endings(): void
    {
        $this->env("APP_NAME=Nitro\r\nAPP_KEY=\r\n");

        $this->command()->handle('key:generate', []);

        $contents = $this->readEnv();

        $this->assertSame(
            substr_count($contents, "\n"),
            substr_count($contents, "\r\n"),
            'every newline must still be preceded by a carriage return'
        );
    }

    public function test_appending_to_a_crlf_file_adds_one_key_line(): void
    {
        $this->env("APP_NAME=Nitro\r\n");

        $this->command()->handle('key:generate', []);

        $this->assertStringStartsWith('base64:', (string) $this->key());
    }

    public function test_a_crlf_file_with_a_real_key_is_still_protected(): void
    {
        $this->env("APP_KEY=base64:EXISTING\r\n");

        $code = $this->command()->handle('key:generate', []);

        $this->assertNotSame(0, $code, 'an existing key must not be overwritten without --force');
        $this->assertSame('base64:EXISTING', $this->key());
    }

    /** A value of only spaces is no key at all. */
    public function test_a_whitespace_only_key_is_replaced(): void
    {
        $this->env("APP_KEY=   \n");

        $this->command()->handle('key:generate', []);

        $this->assertStringStartsWith('base64:', (string) $this->key());
    }
}
