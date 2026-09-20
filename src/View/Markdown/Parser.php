<?php

namespace Nitro\View\Markdown;

use Nitro\View\Contracts\MarkdownParser;

/**
 * Nitro's own Markdown parser — no third-party dependency.
 *
 * Covers the subset a documentation or content page actually uses: headings,
 * paragraphs, emphasis, links, images, lists, blockquotes, fenced and indented
 * code, thematic breaks, pipe tables and link definitions.
 *
 * Raw HTML in the source is escaped unless it is explicitly allowed, because a
 * Markdown document is often the least trusted text on the page.
 */
final class Parser implements MarkdownParser
{
    /**
     * @param bool $allowHtml  Whether raw tags pass through instead of being escaped.
     * @param bool $hardBreaks Whether every newline becomes a `<br>`.
     * @param array<string, string|bool> $linkAttributes Attributes added to links that stay in the application.
     * @param string|null $baseUrl   The application's own URL, which decides what counts as internal.
     * @param bool $fragments        Whether a link to a place on this page counts as internal.
     */
    public function __construct(
        private readonly bool $allowHtml = false,
        private readonly bool $hardBreaks = false,
        private readonly array $linkAttributes = [],
        private readonly ?string $baseUrl = null,
        private readonly bool $fragments = false,
    ) {
    }

    /**
     * Convert Markdown source into an HTML fragment.
     */
    public function toHtml(string $markdown): string
    {
        $lines      = $this->lines($markdown);
        $references = [];
        $lines      = $this->extractReferences($lines, $references);

        $inline = new InlineParser(
            $this->allowHtml,
            $this->hardBreaks,
            $references,
            $this->linkAttributes,
            $this->baseUrl,
            $this->fragments,
        );

        return trim($this->blocks($lines, $inline));
    }

    /**
     * Normalise line endings and tabs, then split into lines.
     *
     * @return array<int, string>
     */
    private function lines(string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $markdown = str_replace("\t", '    ', $markdown);

        return explode("\n", $markdown);
    }

    /**
     * Pull `[label]: url "title"` definitions out and off the page.
     *
     * @param  array<int, string> $lines
     * @param  array<string, array{url: string, title: string|null}> $references
     * @return array<int, string>
     */
    private function extractReferences(array $lines, array &$references): array
    {
        foreach ($lines as $index => $line) {
            $matched = preg_match(
                '/^ {0,3}\[([^\]]+)\]:\s*<?([^\s>]+)>?(?:\s+(?:"([^"]*)"|\'([^\']*)\'|\(([^)]*)\)))?\s*$/',
                $line,
                $match
            );

            if ($matched !== 1) {
                continue;
            }

            $title = $match[3] ?? '';
            $title = $title !== '' ? $title : ($match[4] ?? '');
            $title = $title !== '' ? $title : ($match[5] ?? '');

            $references[strtolower(trim($match[1]))] = [
                'url'   => $match[2],
                'title' => $title !== '' ? $title : null,
            ];

            $lines[$index] = '';
        }

        return $lines;
    }

    /**
     * Walk the lines, emitting one block at a time.
     *
     * @param array<int, string> $lines
     */
    private function blocks(array $lines, InlineParser $inline): string
    {
        $html  = '';
        $index = 0;
        $count = count($lines);

        while ($index < $count) {
            if (trim($lines[$index]) === '') {
                $index++;
                continue;
            }

            $html .= $this->block($lines, $index, $count, $inline);
        }

        return $html;
    }

    /**
     * Emit the block starting at $index, advancing past the lines it consumed.
     *
     * @param array<int, string> $lines
     */
    private function block(array $lines, int &$index, int $count, InlineParser $inline): string
    {
        $line = $lines[$index];

        return match (true) {
            $this->isFence($line)          => $this->fencedCode($lines, $index, $count),
            $this->isHeading($line)        => $this->heading($lines, $index, $inline),
            $this->isThematicBreak($line)  => $this->thematicBreak($index),
            $this->isQuote($line)          => $this->quote($lines, $index, $count, $inline),
            $this->isTable($lines, $index, $count) => $this->table($lines, $index, $count, $inline),
            $this->isListItem($line)       => $this->list($lines, $index, $count, $inline),
            $this->isIndentedCode($line)   => $this->indentedCode($lines, $index, $count),
            $this->isHtmlBlock($line)      => $this->htmlBlock($lines, $index, $count),
            default                        => $this->paragraph($lines, $index, $count, $inline),
        };
    }

    /**
     * Whether a line would start a block other than a paragraph.
     *
     * @param array<int, string> $lines
     */
    private function startsBlock(array $lines, int $index, int $count): bool
    {
        $line = $lines[$index];

        return $this->isFence($line)
            || $this->isHeading($line)
            || $this->isThematicBreak($line)
            || $this->isQuote($line)
            || $this->isListItem($line)
            || $this->isHtmlBlock($line);
    }

    // ─── Fenced code ──────────────────────────────────────────

    private function isFence(string $line): bool
    {
        return preg_match('/^ {0,3}(`{3,}|~{3,})/', $line) === 1;
    }

    /**
     * ```` ```php ```` through to the matching closing fence.
     *
     * @param array<int, string> $lines
     */
    private function fencedCode(array $lines, int &$index, int $count): string
    {
        preg_match('/^ {0,3}(`{3,}|~{3,})\s*(\S*)/', $lines[$index], $match);

        $fence    = $match[1];
        $language = $match[2];
        $indent   = strlen($lines[$index]) - strlen(ltrim($lines[$index]));
        $body     = [];

        $index++;

        while ($index < $count) {
            if (preg_match('/^ {0,3}' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}\s*$/', $lines[$index]) === 1) {
                $index++;
                break;
            }

            $body[] = $this->stripIndent($lines[$index], $indent);
            $index++;
        }

        $attribute = $language !== ''
            ? ' class="language-' . htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
            : '';

        return '<pre><code' . $attribute . '>'
            . htmlspecialchars(implode("\n", $body), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . "</code></pre>\n";
    }

    // ─── Headings ─────────────────────────────────────────────

    private function isHeading(string $line): bool
    {
        return preg_match('/^ {0,3}#{1,6}(?:\s|$)/', $line) === 1;
    }

    /**
     * @param array<int, string> $lines
     */
    private function heading(array $lines, int &$index, InlineParser $inline): string
    {
        preg_match('/^ {0,3}(#{1,6})\s*(.*?)\s*$/', $lines[$index], $match);

        $level = strlen($match[1]);
        $text  = preg_replace('/\s*#+\s*$/', '', $match[2]) ?? $match[2];

        $index++;

        return '<h' . $level . '>' . $inline->parse($text) . '</h' . $level . ">\n";
    }

    // ─── Thematic break ───────────────────────────────────────

    private function isThematicBreak(string $line): bool
    {
        return preg_match('/^ {0,3}(?:(?:\*\s*){3,}|(?:-\s*){3,}|(?:_\s*){3,})$/', $line) === 1;
    }

    private function thematicBreak(int &$index): string
    {
        $index++;

        return "<hr>\n";
    }

    // ─── Blockquote ───────────────────────────────────────────

    private function isQuote(string $line): bool
    {
        return preg_match('/^ {0,3}>/', $line) === 1;
    }

    /**
     * Strip one level of `>` and parse what is left as its own document.
     *
     * @param array<int, string> $lines
     */
    private function quote(array $lines, int &$index, int $count, InlineParser $inline): string
    {
        $body = [];

        while ($index < $count) {
            $line = $lines[$index];

            if ($this->isQuote($line)) {
                $body[] = preg_replace('/^ {0,3}> ?/', '', $line) ?? $line;
            } elseif (trim($line) !== '' && ! $this->startsBlock($lines, $index, $count)) {
                $body[] = $line;
            } else {
                break;
            }

            $index++;
        }

        return "<blockquote>\n" . $this->blocks($body, $inline) . "</blockquote>\n";
    }

    // ─── Tables ───────────────────────────────────────────────

    /**
     * A header row followed by a row of dashes, pipe-separated.
     *
     * @param array<int, string> $lines
     */
    private function isTable(array $lines, int $index, int $count): bool
    {
        return $index + 1 < $count
            && str_contains($lines[$index], '|')
            && preg_match('/^ {0,3}\|?(?:\s*:?-+:?\s*\|)+\s*:?-*:?\s*\|?\s*$/', $lines[$index + 1]) === 1;
    }

    /**
     * @param array<int, string> $lines
     */
    private function table(array $lines, int &$index, int $count, InlineParser $inline): string
    {
        $headers    = $this->cells($lines[$index]);
        $alignments = array_map(
            static function (string $spec): string {
                $spec  = trim($spec);
                $left  = str_starts_with($spec, ':');
                $right = str_ends_with($spec, ':');

                return match (true) {
                    $left && $right => ' style="text-align:center"',
                    $right          => ' style="text-align:right"',
                    $left           => ' style="text-align:left"',
                    default         => '',
                };
            },
            $this->cells($lines[$index + 1])
        );

        $index += 2;

        $html = "<table>\n<thead>\n<tr>\n";
        foreach ($headers as $position => $header) {
            $html .= '<th' . ($alignments[$position] ?? '') . '>' . $inline->parse($header) . "</th>\n";
        }
        $html .= "</tr>\n</thead>\n<tbody>\n";

        while ($index < $count && trim($lines[$index]) !== '' && str_contains($lines[$index], '|')) {
            $html .= "<tr>\n";

            foreach ($this->cells($lines[$index]) as $position => $cell) {
                $html .= '<td' . ($alignments[$position] ?? '') . '>' . $inline->parse($cell) . "</td>\n";
            }

            $html .= "</tr>\n";
            $index++;
        }

        return $html . "</tbody>\n</table>\n";
    }

    /**
     * Split a table row on unescaped pipes.
     *
     * @return array<int, string>
     */
    private function cells(string $row): array
    {
        $row   = trim($row);
        $row   = preg_replace('/^\|/', '', $row) ?? $row;
        $row   = preg_replace('/\|$/', '', $row) ?? $row;
        $cells = preg_split('/(?<!\\\\)\|/', $row) ?: [];

        return array_map(static fn (string $cell): string => trim(str_replace('\\|', '|', $cell)), $cells);
    }

    // ─── Lists ────────────────────────────────────────────────

    private function isListItem(string $line): bool
    {
        return preg_match('/^ {0,3}(?:[-+*]|\d{1,9}[.)])(?:\s+\S|\s*$)/', $line) === 1
            && ! $this->isThematicBreak($line);
    }

    /**
     * Gather every item at this level, then parse each item's own lines.
     *
     * @param array<int, string> $lines
     */
    private function list(array $lines, int &$index, int $count, InlineParser $inline): string
    {
        preg_match('/^( {0,3})([-+*]|\d{1,9}[.)])/', $lines[$index], $opening);

        $ordered = ! in_array($opening[2], ['-', '+', '*'], true);
        $start   = $ordered ? (int) rtrim($opening[2], '.)') : 1;

        /** @var array<int, array{indent: int, lines: array<int, string>}> $items */
        $items = [];
        $loose = false;
        $blank = false;

        while ($index < $count) {
            $line = $lines[$index];

            if (trim($line) === '') {
                $blank = true;
                $index++;
                continue;
            }

            $matched = preg_match('/^( *)([-+*]|\d{1,9}[.)])(\s+)(.*)$/', $line, $match);
            $indent  = strlen($line) - strlen(ltrim($line));

            /*
             * A marker only starts a sibling item when it sits left of where
             * this item's own content begins; anything at or past that column
             * belongs to the item, which is what makes a nested list nest.
             */
            $sibling = $matched === 1
                && ! $this->isThematicBreak($line)
                && ($items === [] || $indent < $items[array_key_last($items)]['indent']);

            if ($sibling) {
                if ($items !== [] && $blank) {
                    $loose = true;
                }

                $items[] = [
                    'indent' => strlen($match[1]) + strlen($match[2]) + strlen($match[3]),
                    'lines'  => [$match[4]],
                ];
                $blank = false;
                $index++;
                continue;
            }

            if ($items !== [] && $indent >= 2) {
                $last = array_key_last($items);

                if ($blank) {
                    $items[$last]['lines'][] = '';
                    $loose = true;
                }

                $items[$last]['lines'][] = $this->stripIndent($line, $items[$last]['indent']);
                $blank = false;
                $index++;
                continue;
            }

            break;
        }

        $html = '';
        foreach ($items as $item) {
            $html .= '<li>' . $this->itemHtml($item['lines'], $inline, ! $loose) . "</li>\n";
        }

        $attribute = $ordered && $start !== 1 ? ' start="' . $start . '"' : '';
        $tag       = $ordered ? 'ol' : 'ul';

        return '<' . $tag . $attribute . ">\n" . $html . '</' . $tag . ">\n";
    }

    /**
     * Render one item, unwrapping the paragraph when the list is tight.
     *
     * @param array<int, string> $item
     */
    private function itemHtml(array $item, InlineParser $inline, bool $tight): string
    {
        $html = trim($this->blocks($item, $inline));

        if ($tight && preg_match('/^<p>([\s\S]*)<\/p>$/', $html, $match) === 1 && ! str_contains($match[1], '<p>')) {
            return $match[1];
        }

        return "\n" . $html . "\n";
    }

    // ─── Indented code ────────────────────────────────────────

    private function isIndentedCode(string $line): bool
    {
        return preg_match('/^ {4,}\S/', $line) === 1;
    }

    /**
     * @param array<int, string> $lines
     */
    private function indentedCode(array $lines, int &$index, int $count): string
    {
        $body = [];

        while ($index < $count) {
            $line = $lines[$index];

            if (trim($line) === '') {
                $body[] = '';
                $index++;
                continue;
            }

            if (! $this->isIndentedCode($line)) {
                break;
            }

            $body[] = $this->stripIndent($line, 4);
            $index++;
        }

        while ($body !== [] && end($body) === '') {
            array_pop($body);
        }

        return '<pre><code>'
            . htmlspecialchars(implode("\n", $body), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . "</code></pre>\n";
    }

    // ─── Raw HTML ─────────────────────────────────────────────

    private function isHtmlBlock(string $line): bool
    {
        return $this->allowHtml && preg_match('/^ {0,3}<[a-zA-Z!\/]/', $line) === 1;
    }

    /**
     * @param array<int, string> $lines
     */
    private function htmlBlock(array $lines, int &$index, int $count): string
    {
        $body = [];

        while ($index < $count && trim($lines[$index]) !== '') {
            $body[] = $lines[$index];
            $index++;
        }

        return implode("\n", $body) . "\n";
    }

    // ─── Paragraph ────────────────────────────────────────────

    /**
     * Everything up to the next blank line or the next block.
     *
     * @param array<int, string> $lines
     */
    private function paragraph(array $lines, int &$index, int $count, InlineParser $inline): string
    {
        $body = [];

        while ($index < $count && trim($lines[$index]) !== '') {
            if ($body !== [] && $this->startsBlock($lines, $index, $count)) {
                break;
            }

            if ($body !== [] && $this->isTable($lines, $index, $count)) {
                break;
            }

            /* Left-trimmed only: two trailing spaces are a line break. */
            $body[] = ltrim($lines[$index]);
            $index++;
        }

        return '<p>' . $inline->parse(implode("\n", $body)) . "</p>\n";
    }

    /**
     * Remove up to $width leading spaces, leaving any deeper indent in place.
     */
    private function stripIndent(string $line, int $width): string
    {
        for ($removed = 0; $removed < $width; $removed++) {
            if (! str_starts_with($line, ' ')) {
                break;
            }

            $line = substr($line, 1);
        }

        return $line;
    }
}
