<?php

namespace Nitro\Foundation;

/**
 * Reads a .env file into the process environment.
 *
 * {@see parse()} maps the file's contents to a key/value array;
 * {@see load()} applies that to $_ENV, $_SERVER and putenv().
 *
 * The grammar:
 *
 *   KEY=value                 unquoted; trimmed, ends at a whitespace-preceded #
 *   export KEY=value          the export prefix is ignored
 *   KEY="value"               escapes (\n \t \r \f \v \" \\ \$) and ${VAR}
 *   KEY='value'               literal, no escapes or interpolation
 *   KEY=                      empty string
 *   # comment                 whole-line comment
 *
 * A quoted value may span lines until its closing quote. Interpolation reads
 * values defined earlier in the same file first, then the existing environment.
 */
final class Env
{
    /** Keys start with a letter or underscore; dots are allowed after. */
    private const KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_.]*$/';

    /**
     * Read $directory/$file and apply it to the environment.
     *
     * A missing or unreadable file yields an empty array.
     *
     * @param  bool $overwrite Replace values already present in the environment.
     * @return array<string, string> What the file defined.
     */
    public static function load(string $directory, string $file = '.env', bool $overwrite = false): array
    {
        $path = rtrim($directory, "/\\") . DIRECTORY_SEPARATOR . $file;

        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $values = self::parse($contents);

        foreach ($values as $key => $value) {
            self::write($key, $value, $overwrite);
        }

        return $values;
    }

    /**
     * Parse the contents of a .env file.
     *
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $values = [];
        $count = count($lines);

        for ($index = 0; $index < $count; $index++) {
            $line = ltrim($lines[$index]);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            $separator = strpos($line, '=');

            if ($separator === false) {
                continue;
            }

            $key = rtrim(substr($line, 0, $separator));

            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                continue;
            }

            $rest = substr($line, $separator + 1);
            $raw = ltrim($rest);

            if ($raw !== '' && $raw[0] === '#' && $rest !== $raw) {
                $values[$key] = '';
                continue;
            }

            if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) {
                $values[$key] = self::readQuoted($raw, $lines, $index, $values);
                continue;
            }

            $values[$key] = self::interpolate(self::stripComment($raw), $values);
        }

        return $values;
    }

    /**
     * Read a quoted value, consuming further lines until the closing quote.
     *
     * @param  array<int, string>    $lines
     * @param  int                   $index  Advanced past any lines consumed.
     * @param  array<string, string> $known  Values defined earlier in the file.
     */
    private static function readQuoted(string $raw, array $lines, int &$index, array $known): string
    {
        $quote = $raw[0];
        $buffer = substr($raw, 1);
        $count = count($lines);

        while (true) {
            $end = self::findClosingQuote($buffer, $quote);

            if ($end !== null) {
                $value = substr($buffer, 0, $end);

                return $quote === "'"
                    ? str_replace("\\'", "'", $value)
                    : self::unescape(self::interpolate($value, $known));
            }

            // No closing quote on this line: the value continues on the next.
            if (++$index >= $count) {
                return $quote === "'"
                    ? $buffer
                    : self::unescape(self::interpolate($buffer, $known));
            }

            $buffer .= "\n" . $lines[$index];
        }
    }

    /**
     * The offset of the closing quote, ignoring one that is escaped.
     */
    private static function findClosingQuote(string $value, string $quote): ?int
    {
        $length = strlen($value);

        for ($offset = 0; $offset < $length; $offset++) {
            if ($value[$offset] === '\\') {
                $offset++;
                continue;
            }

            if ($value[$offset] === $quote) {
                return $offset;
            }
        }

        return null;
    }

    /**
     * Drop an inline comment from an unquoted value. A # starts a comment only
     * when whitespace precedes it.
     */
    private static function stripComment(string $value): string
    {
        $length = strlen($value);

        for ($offset = 0; $offset < $length; $offset++) {
            if ($value[$offset] !== '#') {
                continue;
            }

            if ($offset > 0 && ($value[$offset - 1] === ' ' || $value[$offset - 1] === "\t")) {
                return rtrim(substr($value, 0, $offset));
            }
        }

        return rtrim($value);
    }

    /** Resolve the escape sequences a double-quoted value may contain. */
    private static function unescape(string $value): string
    {
        return preg_replace_callback(
            '/\\\\(.)/s',
            static fn (array $matches): string => match ($matches[1]) {
                'n'     => "\n",
                'r'     => "\r",
                't'     => "\t",
                'f'     => "\f",
                'v'     => "\v",
                '"'     => '"',
                "'"     => "'",
                '$'     => '$',
                '\\'    => '\\',
                default => '\\' . $matches[1],
            },
            $value
        ) ?? $value;
    }

    /**
     * Replace ${VAR} with a value defined earlier in the file, or one already
     * in the environment. An unknown name resolves to an empty string. A
     * backslash before the $ keeps the placeholder literal.
     *
     * @param array<string, string> $known
     */
    private static function interpolate(string $value, array $known): string
    {
        if (! str_contains($value, '${')) {
            return $value;
        }

        return preg_replace_callback(
            '/(?<!\\\\)\$\{([A-Za-z_][A-Za-z0-9_.]*)\}/',
            static function (array $matches) use ($known): string {
                $name = $matches[1];

                if (array_key_exists($name, $known)) {
                    return $known[$name];
                }

                $existing = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

                return is_string($existing) ? $existing : '';
            },
            $value
        ) ?? $value;
    }

    /** Put one value into $_ENV, $_SERVER and putenv(). */
    private static function write(string $key, string $value, bool $overwrite): void
    {
        if (! $overwrite && self::alreadySet($key)) {
            return;
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;

        putenv($key . '=' . $value);
    }

    private static function alreadySet(string $key): bool
    {
        return array_key_exists($key, $_ENV)
            || array_key_exists($key, $_SERVER)
            || getenv($key) !== false;
    }
}
