<?php

namespace Nitro\Console\Support;

/**
 * Reading keys one at a time, rather than a line at a time.
 *
 * An arrow-key menu needs the terminal in raw mode: no line buffering, so a
 * keypress arrives immediately, and no echo, so the escape sequences do not
 * print themselves. On Unix that is `stty`; Windows has no equivalent a PHP
 * process can reach, so {@see supportsRawMode()} answers false there and the
 * caller falls back to a prompt that reads a whole line.
 */
final class Terminal
{
    /** Keys a menu cares about, as the bytes a terminal actually sends. */
    public const UP = 'up';
    public const DOWN = 'down';
    public const ENTER = 'enter';
    public const SPACE = 'space';
    public const CANCEL = 'cancel';
    public const OTHER = 'other';

    private ?string $saved = null;

    /** Whether this instance actually changed the terminal. */
    private bool $entered = false;

    /** Whether keys can be read one at a time here. */
    public static function supportsRawMode(): bool
    {
        if (DIRECTORY_SEPARATOR === '\\' || ! function_exists('shell_exec')) {
            return false;
        }

        if (! self::hasInputTerminal()) {
            return false;
        }

        // `stty -g` prints the current settings, and fails without a terminal.
        return is_string(@shell_exec('stty -g 2>/dev/null'));
    }

    /**
     * How many columns wide the terminal is.
     *
     * COLUMNS is honoured first so a caller can pin the width — a test, or a
     * command rendering to a known size — before any shelling out happens.
     */
    public static function width(int $default = 80): int
    {
        $columns = getenv('COLUMNS');

        if ($columns !== false && is_numeric($columns)) {
            return max(20, (int) $columns);
        }

        if (DIRECTORY_SEPARATOR !== '\\' && function_exists('shell_exec')) {
            $size = @shell_exec('stty size 2>/dev/null');

            if (is_string($size) && preg_match('/\d+\s+(\d+)/', $size, $matches) === 1) {
                return max(20, (int) $matches[1]);
            }
        }

        return $default;
    }

    /** Whether STDIN is attached to a terminal, on any platform. */
    public static function hasInputTerminal(): bool
    {
        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDIN);
        }

        return function_exists('posix_isatty') && @posix_isatty(STDIN);
    }

    /**
     * Put the terminal into raw mode, remembering how to put it back.
     *
     * The previous settings are captured rather than assumed, so restoring
     * returns the terminal to what the user had rather than to some default —
     * a shell left without echo after a command exits is unusable.
     */
    public function enterRawMode(): void
    {
        if (! self::supportsRawMode()) {
            return;
        }

        $saved = @shell_exec('stty -g 2>/dev/null');

        $this->saved = is_string($saved) ? trim($saved) : null;
        $this->entered = true;

        @shell_exec('stty -icanon -echo 2>/dev/null');
    }

    /**
     * Put the terminal back the way it was.
     *
     * A no-op when this instance never changed anything — restoring a
     * terminal nobody touched would run `stty` on a platform that has none,
     * and the shell's complaint is printed by the shell, past anything PHP
     * can suppress.
     */
    public function restore(): void
    {
        if (! $this->entered) {
            return;
        }

        $this->entered = false;

        if ($this->saved !== null && $this->saved !== '') {
            @shell_exec('stty ' . escapeshellarg($this->saved) . ' 2>/dev/null');
            $this->saved = null;

            return;
        }

        @shell_exec('stty icanon echo 2>/dev/null');
    }

    /**
     * The next keypress, as one of this class's constants.
     *
     * An arrow key arrives as three bytes — escape, '[', then a letter — so
     * the two after the escape are read before deciding what was pressed.
     */
    public function readKey(): string
    {
        $byte = fread(STDIN, 1);

        if ($byte === false || $byte === '') {
            return self::CANCEL;
        }

        return match ($byte) {
            "\n", "\r" => self::ENTER,
            ' '        => self::SPACE,
            "\x03", "\x04", "q" => self::CANCEL,
            "\e"       => $this->readEscapeSequence(),
            default    => self::OTHER,
        };
    }

    /** What followed an escape byte: an arrow, or something we ignore. */
    private function readEscapeSequence(): string
    {
        if (fread(STDIN, 1) !== '[') {
            return self::OTHER;
        }

        return match (fread(STDIN, 1)) {
            'A' => self::UP,
            'B' => self::DOWN,
            default => self::OTHER,
        };
    }

    /**
     * Read a line without echoing it, for a password.
     *
     * Windows has no stty, but it does have PowerShell, whose Read-Host can
     * mask input — which is why this is worth doing rather than letting the
     * password print itself across the screen.
     */
    public static function readHidden(): ?string
    {
        /*
         * Nothing to hide from when nobody is typing, and asking anyway is
         * dangerous: the Windows path below starts PowerShell, whose Read-Host
         * waits for a keypress that a redirected stdin will never deliver, so
         * the command hangs instead of finishing. Answered here, before any of
         * that starts.
         */
        if (! self::hasInputTerminal()) {
            return null;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return self::readHiddenOnWindows();
        }

        if (! function_exists('shell_exec')) {
            return null;
        }

        @shell_exec('stty -echo 2>/dev/null');
        $answer = rtrim((string) fgets(STDIN), "\r\n");
        @shell_exec('stty echo 2>/dev/null');

        return $answer;
    }

    /** @return string|null Null when PowerShell is unavailable to ask. */
    private static function readHiddenOnWindows(): ?string
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        $script = '$s = Read-Host -AsSecureString; '
            . '[Runtime.InteropServices.Marshal]::PtrToStringAuto('
            . '[Runtime.InteropServices.Marshal]::SecureStringToBSTR($s))';

        $answer = @shell_exec('powershell -NoProfile -Command ' . escapeshellarg($script));

        return is_string($answer) ? rtrim($answer, "\r\n") : null;
    }
}
