<?php

namespace Nitro\Console\Support;

/**
 * Parses Symfony/Laravel-style output tags into ANSI escape codes (or strips
 * them when the terminal isn't colour-capable). Supports the named styles
 * <info> <comment> <question> <error> and dynamic tags such as
 * <fg=green>, <bg=red>, <fg=yellow;options=bold>, closed by </>.
 */
class Style
{
    protected bool $decorated;

    protected static array $fg = [
        'black' => 30, 'red' => 31, 'green' => 32, 'yellow' => 33, 'blue' => 34,
        'magenta' => 35, 'cyan' => 36, 'white' => 37, 'gray' => 90, 'default' => 39,
    ];

    protected static array $bg = [
        'black' => 40, 'red' => 41, 'green' => 42, 'yellow' => 43, 'blue' => 44,
        'magenta' => 45, 'cyan' => 46, 'white' => 47, 'gray' => 100, 'default' => 49,
    ];

    /** Named styles (Laravel/Symfony defaults). */
    protected static array $named = [
        'info'     => ['fg' => 'green'],
        'comment'  => ['fg' => 'yellow'],
        'question' => ['fg' => 'black', 'bg' => 'cyan'],
        'error'    => ['fg' => 'white', 'bg' => 'red'],
        'warning'  => ['fg' => 'black', 'bg' => 'yellow'],
    ];

    public function __construct(?bool $decorated = null)
    {
        $this->decorated = $decorated ?? self::terminalSupportsColor();
    }

    public function isDecorated(): bool
    {
        return $this->decorated;
    }

    /** Turn tag markup into ANSI (or plain text when not decorated). */
    public function format(string $text): string
    {
        return preg_replace_callback('~<(/?)([a-z0-9=;,#_-]*)>~i', function (array $m): string {
            if ($m[1] === '/') {
                return $this->decorated ? "\e[0m" : '';
            }
            if ($m[2] === '') {
                return $m[0];
            }

            return $this->decorated ? $this->ansiFor($m[2]) : '';
        }, $text) ?? $text;
    }

    /** Wrap text in explicit fg/bg/bold ANSI (no tags). */
    public function apply(string $text, ?string $fg = null, ?string $bg = null, bool $bold = false): string
    {
        if (! $this->decorated) {
            return $text;
        }

        $codes = $this->codes($fg, $bg, $bold);

        return $codes === '' ? $text : $codes . $text . "\e[0m";
    }

    /** The visible width of a string, ignoring tags and ANSI codes. */
    public static function width(string $text): int
    {
        $text = preg_replace('~<(/?)[a-z0-9=;,#_-]*>~i', '', $text) ?? $text;
        $text = preg_replace('/\e\[[0-9;]*m/', '', $text) ?? $text;

        return mb_strlen($text);
    }

    protected function ansiFor(string $tag): string
    {
        if (isset(self::$named[$tag])) {
            $s = self::$named[$tag];
            return $this->codes($s['fg'] ?? null, $s['bg'] ?? null, false);
        }

        $fg = $bg = null;
        $bold = false;

        foreach (explode(';', $tag) as $part) {
            if (str_starts_with($part, 'fg=')) {
                $fg = substr($part, 3);
            } elseif (str_starts_with($part, 'bg=')) {
                $bg = substr($part, 3);
            } elseif (str_starts_with($part, 'options=')) {
                $bold = str_contains($part, 'bold');
            }
        }

        return $this->codes($fg, $bg, $bold);
    }

    protected function codes(?string $fg, ?string $bg, bool $bold): string
    {
        $codes = [];
        if ($bold) {
            $codes[] = 1;
        }
        if ($fg !== null && isset(self::$fg[$fg])) {
            $codes[] = self::$fg[$fg];
        }
        if ($bg !== null && isset(self::$bg[$bg])) {
            $codes[] = self::$bg[$bg];
        }

        return $codes === [] ? '' : "\e[" . implode(';', $codes) . 'm';
    }

    protected static function terminalSupportsColor(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return getenv('ANSICON') !== false
                || getenv('WT_SESSION') !== false
                || getenv('TERM_PROGRAM') === 'vscode'
                || function_exists('sapi_windows_vt100_support');
        }

        return function_exists('posix_isatty') ? @posix_isatty(STDOUT) : true;
    }
}
