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

    protected static array $foreground = [
        'black' => 30, 'red' => 31, 'green' => 32, 'yellow' => 33, 'blue' => 34,
        'magenta' => 35, 'cyan' => 36, 'white' => 37, 'gray' => 90, 'default' => 39,
    ];

    protected static array $background = [
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
        return preg_replace_callback('~<(/?)([a-z0-9=;,#_-]*)>~i', function (array $matches): string {
            if ($matches[1] === '/') {
                return $this->decorated ? "\e[0m" : '';
            }
            if ($matches[2] === '') {
                return $matches[0];
            }

            return $this->decorated ? $this->ansiFor($matches[2]) : '';
        }, $text) ?? $text;
    }

    /** Wrap text in explicit fg/bg/bold ANSI (no tags). */
    public function apply(string $text, ?string $foreground = null, ?string $background = null, bool $bold = false): string
    {
        if (! $this->decorated) {
            return $text;
        }

        $codes = $this->codes($foreground, $background, $bold);

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
            $style = self::$named[$tag];
            return $this->codes($style['fg'] ?? null, $style['bg'] ?? null, false);
        }

        $foreground = $background = null;
        $bold = false;

        foreach (explode(';', $tag) as $part) {
            if (str_starts_with($part, 'fg=')) {
                $foreground = substr($part, 3);
            } elseif (str_starts_with($part, 'bg=')) {
                $background = substr($part, 3);
            } elseif (str_starts_with($part, 'options=')) {
                $bold = str_contains($part, 'bold');
            }
        }

        return $this->codes($foreground, $background, $bold);
    }

    protected function codes(?string $foreground, ?string $background, bool $bold): string
    {
        $codes = [];
        if ($bold) {
            $codes[] = 1;
        }
        if ($foreground !== null && isset(self::$foreground[$foreground])) {
            $codes[] = self::$foreground[$foreground];
        }
        if ($background !== null && isset(self::$background[$background])) {
            $codes[] = self::$background[$background];
        }

        return $codes === [] ? '' : "\e[" . implode(';', $codes) . 'm';
    }

    protected static function terminalSupportsColor(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        /*
         * Asked first, and on every platform: output that is piped or
         * redirected has no terminal to colour, whoever is running. Checking
         * the Windows environment variables without this said yes to a
         * redirected file as readily as to a console, so `nitro route:list >
         * routes.txt` wrote escape sequences into the file.
         */
        if (! self::isTerminal()) {
            return false;
        }

        // A terminal on Windows still has to be one that understands ANSI.
        if (DIRECTORY_SEPARATOR === '\\') {
            return getenv('ANSICON') !== false
                || getenv('WT_SESSION') !== false
                || getenv('TERM_PROGRAM') === 'vscode'
                || function_exists('sapi_windows_vt100_support');
        }

        return true;
    }

    /**
     * Whether STDOUT is attached to a terminal.
     *
     * stream_isatty answers on every platform, where posix_isatty needs an
     * extension that Windows does not have — which is why Windows used to
     * skip the question entirely.
     */
    protected static function isTerminal(): bool
    {
        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDOUT);
        }

        return function_exists('posix_isatty') && @posix_isatty(STDOUT);
    }
}
