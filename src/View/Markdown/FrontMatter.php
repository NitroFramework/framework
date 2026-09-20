<?php

namespace Nitro\View\Markdown;

/**
 * The `---` fenced block a Markdown document may open with.
 *
 * Deliberately not YAML: one `key: value` pair per line, with strings,
 * numbers, booleans, null and inline `[a, b]` lists. A page's title and its
 * layout are what this is for, and anything richer belongs in a controller.
 */
final class FrontMatter
{
    /**
     * Split a document into its front matter and the body beneath it.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    public static function split(string $source): array
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $source);

        if (preg_match('/^---[ \t]*\n([\s\S]*?)\n---[ \t]*(?:\n|$)/', $normalised, $match) !== 1) {
            return [[], $source];
        }

        return [
            self::parse($match[1]),
            substr($normalised, strlen($match[0])),
        ];
    }

    /**
     * Read the pairs out of the block between the fences.
     *
     * @return array<string, mixed>
     */
    private static function parse(string $block): array
    {
        $values = [];

        foreach (explode("\n", $block) as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_.-]*)\s*:\s*(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $values[$match[1]] = self::value(trim($match[2]));
        }

        return $values;
    }

    /**
     * Give a written value its PHP type.
     */
    private static function value(string $raw): mixed
    {
        if ($raw === '') {
            return '';
        }

        if (preg_match('/^\[(.*)\]$/', $raw, $match) === 1) {
            $items = trim($match[1]) === '' ? [] : explode(',', $match[1]);

            return array_map(static fn (string $item): mixed => self::value(trim($item)), $items);
        }

        if (preg_match('/^"(.*)"$/s', $raw, $match) === 1 || preg_match("/^'(.*)'$/s", $raw, $match) === 1) {
            return $match[1];
        }

        return match (strtolower($raw)) {
            'true'  => true,
            'false' => false,
            'null'  => null,
            default => is_numeric($raw) ? $raw + 0 : $raw,
        };
    }
}
