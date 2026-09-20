<?php

namespace Nitro\View\Markdown;

/**
 * Turns the text inside a block into HTML — emphasis, code spans, links.
 *
 * Constructs that must survive untouched (a code span, an escaped asterisk, a
 * raw tag) are lifted out into placeholders before anything else runs, so the
 * escaping and the emphasis patterns never see them. They come back last,
 * already in their final form.
 */
final class InlineParser
{
    /**
     * A control character no real document contains, wrapped around an index
     * so that escaping and the emphasis patterns pass over a placeholder.
     */
    private const MARKER = "\x1A";

    /** @var array<int, string> */
    private array $held = [];

    /** The rendered attribute string added to an internal link, built once. */
    private readonly string $linkAttributes;

    /**
     * @param bool                  $allowHtml  Whether raw tags pass through instead of being escaped.
     * @param bool                  $hardBreaks Whether every newline becomes a `<br>`.
     * @param array<string, array{url: string, title: string|null}> $references Link definitions collected from the document.
     * @param array<string, string|bool> $linkAttributes Attributes added to links judged internal.
     * @param string|null           $baseUrl    The application's own URL, which decides what counts as internal.
     * @param bool                  $fragments  Whether a link to a place on this page counts as internal.
     */
    public function __construct(
        private readonly bool $allowHtml = false,
        private readonly bool $hardBreaks = false,
        private readonly array $references = [],
        array $linkAttributes = [],
        private readonly ?string $baseUrl = null,
        private readonly bool $fragments = false,
    ) {
        $this->linkAttributes = $this->renderAttributes($linkAttributes);
    }

    /**
     * Turn the configured attributes into the string appended to an anchor.
     *
     * A true value is a bare attribute, a string is a valued one, and anything
     * false is left out. Names are checked rather than trusted, because these
     * go into markup unquoted.
     *
     * @param array<string, string|bool> $attributes
     */
    private function renderAttributes(array $attributes): string
    {
        $rendered = '';

        foreach ($attributes as $name => $value) {
            if ($value === false || $value === null) {
                continue;
            }

            if (preg_match('/^[A-Za-z_:][A-Za-z0-9_:.\-]*$/', $name) !== 1) {
                continue;
            }

            $rendered .= $value === true
                ? ' ' . $name
                : ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return $rendered;
    }

    /**
     * Convert one block's text to HTML.
     */
    public function parse(string $text): string
    {
        $this->held = [];

        $text = $this->holdCodeSpans($text);

        if ($this->allowHtml) {
            $text = $this->holdRawHtml($text);
        }

        $text = $this->holdAutolinks($text);
        $text = $this->holdEscapes($text);

        $text = $this->escape($text);

        $text = $this->parseImages($text);
        $text = $this->parseLinks($text);
        $text = $this->parseEmphasis($text);
        $text = $this->parseBreaks($text);

        return $this->release($text);
    }

    /**
     * Escape text destined for the document body.
     *
     * Quotes are left alone: they are legal in body text, and leaving them
     * readable is what lets the link and image patterns still see a title.
     * Attribute values are escaped separately, where quoting does matter.
     */
    public function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Park a fragment of finished HTML and return the token standing in for it.
     */
    private function hold(string $html): string
    {
        $this->held[] = $html;

        return self::MARKER . (count($this->held) - 1) . self::MARKER;
    }

    /**
     * Put every held fragment back where its token sits.
     */
    private function release(string $text): string
    {
        if ($this->held === []) {
            return $text;
        }

        return preg_replace_callback(
            '/' . self::MARKER . '(\d+)' . self::MARKER . '/',
            fn (array $match): string => $this->held[(int) $match[1]] ?? '',
            $text
        ) ?? $text;
    }

    /**
     * Lift out `` `code` `` before anything can interpret what is inside it.
     */
    private function holdCodeSpans(string $text): string
    {
        return preg_replace_callback(
            '/(?<!`)(`+)(?!`)([\s\S]*?)(?<!`)\1(?!`)/',
            function (array $match): string {
                $code = $match[2];

                if (str_starts_with($code, ' ') && str_ends_with($code, ' ') && trim($code) !== '') {
                    $code = substr($code, 1, -1);
                }

                return $this->hold('<code>' . $this->escape($code) . '</code>');
            },
            $text
        ) ?? $text;
    }

    /**
     * Lift out raw tags and comments, which only happens when HTML is allowed.
     */
    private function holdRawHtml(string $text): string
    {
        return preg_replace_callback(
            '/<!--[\s\S]*?-->|<\/?[a-zA-Z][a-zA-Z0-9-]*(?:\s[^<>]*?)?\/?>/',
            fn (array $match): string => $this->hold($match[0]),
            $text
        ) ?? $text;
    }

    /**
     * Turn `<https://example.test>` into a link.
     */
    private function holdAutolinks(string $text): string
    {
        return preg_replace_callback(
            '/<((?:https?|ftp|mailto):[^<>\s]+)>/i',
            function (array $match): string {
                $url = $this->safeUrl($match[1]);

                return $this->hold(
                    '<a href="' . $this->attribute($url) . '"' . $this->internalAttributes($match[1]) . '>'
                    . $this->escape($match[1]) . '</a>'
                );
            },
            $text
        ) ?? $text;
    }

    /**
     * Lift out backslash-escaped punctuation so it cannot act as a marker.
     */
    private function holdEscapes(string $text): string
    {
        return preg_replace_callback(
            '/\\\\([\\\\`*_{}\[\]()#+\-.!>~|"\'])/',
            fn (array $match): string => $this->hold($this->escape($match[1])),
            $text
        ) ?? $text;
    }

    /**
     * `![alt](src "title")`, and the reference form.
     */
    private function parseImages(string $text): string
    {
        $text = preg_replace_callback(
            '/!\[((?:[^\[\]]|\[[^\[\]]*\])*)\]\(\s*((?:[^\s()]|\([^\s()]*\))*)(?:\s+"([^"]*)")?\s*\)/',
            fn (array $match): string => $this->image($match[1], $match[2], $match[3] ?? null),
            $text
        ) ?? $text;

        return preg_replace_callback(
            '/!\[((?:[^\[\]]|\[[^\[\]]*\])*)\]\[([^\[\]]*)\]/',
            function (array $match): string {
                $reference = $this->reference($match[2] !== '' ? $match[2] : $match[1]);

                if ($reference === null) {
                    return $match[0];
                }

                return $this->image($match[1], $reference['url'], $reference['title']);
            },
            $text
        ) ?? $text;
    }

    /**
     * `[text](href "title")`, the reference form, and the shortcut form.
     */
    private function parseLinks(string $text): string
    {
        $text = preg_replace_callback(
            '/\[((?:[^\[\]]|\[[^\[\]]*\])*)\]\(\s*((?:[^\s()]|\([^\s()]*\))*)(?:\s+"([^"]*)")?\s*\)/',
            fn (array $match): string => $this->link($match[1], $match[2], $match[3] ?? null),
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            '/\[((?:[^\[\]]|\[[^\[\]]*\])*)\]\[([^\[\]]*)\]/',
            function (array $match): string {
                $reference = $this->reference($match[2] !== '' ? $match[2] : $match[1]);

                if ($reference === null) {
                    return $match[0];
                }

                return $this->link($match[1], $reference['url'], $reference['title']);
            },
            $text
        ) ?? $text;

        if ($this->references === []) {
            return $text;
        }

        return preg_replace_callback(
            '/\[([^\[\]]+)\]/',
            function (array $match): string {
                $reference = $this->reference($match[1]);

                if ($reference === null) {
                    return $match[0];
                }

                return $this->link($match[1], $reference['url'], $reference['title']);
            },
            $text
        ) ?? $text;
    }

    /**
     * Build one anchor, holding it so its contents are not reinterpreted.
     */
    private function link(string $text, string $url, ?string $title): string
    {
        $attributes = ' href="' . $this->attribute($this->safeUrl($url)) . '"';

        if ($title !== null && $title !== '') {
            $attributes .= ' title="' . $this->attribute($title) . '"';
        }

        $attributes .= $this->internalAttributes($url);

        return $this->hold('<a' . $attributes . '>') . $text . $this->hold('</a>');
    }

    /**
     * The configured attributes, when this link stays inside the application.
     *
     * A link a document writes is a plain anchor, so a page reached through it
     * is a full load — which drops an application out of client-side
     * navigation on most of the links a content page has. Attributes are added
     * here rather than named here, so the parser carries no opinion about
     * which navigation library is in front of it.
     */
    private function internalAttributes(string $url): string
    {
        return $this->linkAttributes !== '' && $this->isInternal($url)
            ? $this->linkAttributes
            : '';
    }

    /**
     * Whether a link stays within the application.
     *
     * A relative or root-relative link always does. An absolute one does only
     * when it matches the configured base URL, so nothing is added to a link
     * that leaves the site — and never to a scheme that is not the web.
     */
    private function isInternal(string $url): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        if ($url === '') {
            return false;
        }

        if (str_starts_with($url, '#')) {
            return $this->fragments;
        }

        if (str_starts_with($url, '//')) {
            return $this->hostMatches(parse_url('https:' . $url, PHP_URL_HOST));
        }

        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $url, $match) === 1) {
            return in_array(strtolower($match[1]), ['http', 'https'], true)
                && $this->hostMatches(parse_url($url, PHP_URL_HOST));
        }

        return true;
    }

    /**
     * Whether a host is the one the application is served from.
     */
    private function hostMatches(mixed $host): bool
    {
        if ($this->baseUrl === null || ! is_string($host)) {
            return false;
        }

        $base = parse_url($this->baseUrl, PHP_URL_HOST);

        return is_string($base) && strcasecmp($host, $base) === 0;
    }

    /**
     * Build one image element.
     */
    private function image(string $alt, string $url, ?string $title): string
    {
        $attributes = ' src="' . $this->attribute($this->safeUrl($url)) . '"'
            . ' alt="' . $this->attribute($alt) . '"';

        if ($title !== null && $title !== '') {
            $attributes .= ' title="' . $this->attribute($title) . '"';
        }

        return $this->hold('<img' . $attributes . '>');
    }

    /**
     * Look a link definition up by label, which matches case-insensitively.
     *
     * @return array{url: string, title: string|null}|null
     */
    private function reference(string $label): ?array
    {
        return $this->references[strtolower(trim($label))] ?? null;
    }

    /**
     * `**strong**`, `*emphasis*`, `~~struck~~`.
     *
     * Underscores are only markers at a word boundary, so `snake_case_name`
     * survives a document that never meant to emphasise anything.
     */
    private function parseEmphasis(string $text): string
    {
        $patterns = [
            '/\*\*(?=\S)([\s\S]*?\S)\*\*/'        => '<strong>$1</strong>',
            '/(?<![\w\\\\])__(?=\S)([\s\S]*?\S)__(?!\w)/' => '<strong>$1</strong>',
            '/~~(?=\S)([\s\S]*?\S)~~/'            => '<del>$1</del>',
            '/\*(?=\S)([\s\S]*?\S)\*/'            => '<em>$1</em>',
            '/(?<![\w\\\\])_(?=\S)([\s\S]*?\S)_(?!\w)/'   => '<em>$1</em>',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        return $text;
    }

    /**
     * Two trailing spaces, or a trailing backslash, end the line.
     */
    private function parseBreaks(string $text): string
    {
        if ($this->hardBreaks) {
            return str_replace("\n", "<br>\n", $text);
        }

        return preg_replace('/(?: {2,}|\\\\)\n/', "<br>\n", $text) ?? $text;
    }

    /**
     * Reject a URL whose scheme could execute, so a link in a document cannot
     * become script the page runs.
     */
    private function safeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        /* No scheme: a relative path, a fragment, a protocol-relative URL. */
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $url, $match) !== 1) {
            return $url;
        }

        $allowed = ['http', 'https', 'mailto', 'tel', 'ftp'];

        return in_array(strtolower($match[1]), $allowed, true) ? $url : '';
    }

    /**
     * Escape a value going into an attribute, where quoting does matter.
     */
    private function attribute(string $value): string
    {
        return htmlspecialchars(
            html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
