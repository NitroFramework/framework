<?php

namespace Nitro\Htmx\Navigation;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Nitro\Http\Request;
use Nitro\Http\Response;

/**
 * Trims a full-page HTML response down to the fragment(s) a Nitro-navigation
 * request asked for.
 *
 * When the client sends `X-Nitro-Navigate: true` plus an `X-Nitro-Select`
 * selector (and optional out-of-band selectors), this extracts the matching
 * nodes and the page title, replacing the response body with just those
 * fragments. This is HTMX/Nitro-navigation behaviour and therefore lives in
 * the HTMX layer; it is wired onto the HTTP kernel's response-ready hook by
 * {@see \Nitro\Foundation\Providers\HtmxServiceProvider} so the core kernel
 * stays unaware of it.
 */
class NitroNavigation
{
    private const NITRO_NAV_SELECT_HEADER = 'X-Nitro-Select';
    private const NITRO_NAV_OOB_HEADER = 'X-Nitro-Select-Oob';

    /**
     * Rewrite the response in place to contain only the selected fragment(s)
     * when the request is a qualifying Nitro-navigation request; otherwise
     * leave it untouched.
     */
    public function prepare(Request $request, Response $response): void
    {
        if (!$this->shouldTrim($request, $response)) {
            return;
        }

        $payload = $this->extractPayload(
            $response->getContent(),
            (string) $request->header(self::NITRO_NAV_SELECT_HEADER, ''),
            (string) $request->header(self::NITRO_NAV_OOB_HEADER, '')
        );

        if ($payload === null) {
            return;
        }

        $response->setContent($payload['html']);
        $response->header('X-Nitro-Fragment', 'true');
        $response->header('X-Nitro-Title', rawurlencode($payload['title']));

        $vary = $this->appendVaryHeaders(
            (string) ($response->header('Vary') ?? ''),
            ['X-Nitro-Navigate', self::NITRO_NAV_SELECT_HEADER, self::NITRO_NAV_OOB_HEADER]
        );

        if ($vary !== '') {
            $response->header('Vary', $vary);
        }
    }

    /**
     * Determine whether the request/response pair is eligible for fragment
     * trimming: a successful HTML GET carrying the navigation header and a
     * non-empty body.
     */
    private function shouldTrim(Request $request, Response $response): bool
    {
        if ($request->method() !== 'GET') {
            return false;
        }

        if ($request->header('X-Nitro-Navigate') !== 'true') {
            return false;
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return false;
        }

        $contentType = strtolower((string) ($response->header('Content-Type') ?? ''));
        if ($contentType !== '' && strpos($contentType, 'text/html') === false) {
            return false;
        }

        return trim($response->getContent()) !== '';
    }

    /**
     * Parse the HTML and extract the primary selected fragment plus any
     * out-of-band fragments and the document title.
     *
     * @return array{html: string, title: string}|null Null when the HTML
     *         can't be parsed or the primary selector matches nothing.
     */
    private function extractPayload(string $html, string $selectSelector, string $oobSelectors): ?array
    {
        $selectSelector = trim($selectSelector);
        if ($selectSelector === '') {
            return null;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded !== true) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $selected = $this->firstNodeForSelector($xpath, $selectSelector);
        if (!$selected instanceof DOMElement) {
            return null;
        }

        $parts = [];
        $seen = [];

        $selectedHtml = $document->saveHTML($selected);
        if ($selectedHtml === false || $selectedHtml === '') {
            return null;
        }

        $parts[] = $selectedHtml;
        $seen[spl_object_hash($selected)] = true;

        foreach ($this->splitSelectorList($oobSelectors) as $selector) {
            $node = $this->firstNodeForSelector($xpath, $selector);
            if (!$node instanceof DOMElement) {
                continue;
            }

            $key = spl_object_hash($node);
            if (isset($seen[$key])) {
                continue;
            }

            $nodeHtml = $document->saveHTML($node);
            if ($nodeHtml === false || $nodeHtml === '') {
                continue;
            }

            $parts[] = $nodeHtml;
            $seen[$key] = true;
        }

        return [
            'html' => implode("\n", $parts),
            'title' => $this->extractTitle($xpath),
        ];
    }

    /**
     * Return the trimmed text of the document's first <title>, or an empty
     * string when there is none.
     */
    private function extractTitle(DOMXPath $xpath): string
    {
        $title = $xpath->query('//title[1]');
        if ($title instanceof DOMNodeList && $title->length > 0) {
            $node = $title->item(0);
            return trim($node ? (string) $node->textContent : '');
        }

        return '';
    }

    /**
     * Resolve a CSS selector to its first matching node, or null when the
     * selector is unsupported or matches nothing.
     */
    private function firstNodeForSelector(DOMXPath $xpath, string $selector): ?DOMNode
    {
        $expression = $this->selectorToXPath(trim($selector));
        if ($expression === null) {
            return null;
        }

        $nodes = $xpath->query($expression);
        if (!$nodes instanceof DOMNodeList || $nodes->length === 0) {
            return null;
        }

        return $nodes->item(0);
    }

    /**
     * Translate a simple CSS selector (#id, .class, [attr], or a tag name)
     * into an XPath expression, or null when it isn't one of those forms.
     */
    private function selectorToXPath(string $selector): ?string
    {
        if ($selector === '') {
            return null;
        }

        if (preg_match('/^#([A-Za-z_][A-Za-z0-9_:\-\.]*)$/', $selector, $matches)) {
            return "//*[@id={$this->xpathLiteral($matches[1])}]";
        }

        if (preg_match('/^\.([A-Za-z_][A-Za-z0-9_\-]*)$/', $selector, $matches)) {
            return "//*[contains(concat(' ', normalize-space(@class), ' '), {$this->xpathLiteral(' ' . $matches[1] . ' ')})]";
        }

        if (preg_match('/^\[([A-Za-z_][A-Za-z0-9_:\-\.]*)(?:=(["\']?)(.*?)\2)?\]$/', $selector, $matches)) {
            $attribute = $matches[1];
            $hasValue = array_key_exists(3, $matches) && $matches[3] !== '';

            if ($hasValue) {
                return "//*[@{$attribute}={$this->xpathLiteral($matches[3])}]";
            }

            return "//*[@{$attribute}]";
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9_:\-]*$/', $selector)) {
            return '//' . $selector;
        }

        return null;
    }

    /**
     * Build an XPath string literal that safely encodes a value which may
     * contain single and/or double quotes (via concat() when needed).
     */
    private function xpathLiteral(string $value): string
    {
        if (strpos($value, "'") === false) {
            return "'" . $value . "'";
        }

        if (strpos($value, '"') === false) {
            return '"' . $value . '"';
        }

        $parts = explode("'", $value);
        $segments = [];

        foreach ($parts as $index => $part) {
            if ($part !== '') {
                $segments[] = "'" . $part . "'";
            }

            if ($index !== count($parts) - 1) {
                $segments[] = "\"'\"";
            }
        }

        return 'concat(' . implode(', ', $segments) . ')';
    }

    /**
     * Split a comma-separated selector list into trimmed, non-empty entries.
     */
    private function splitSelectorList(string $selectors): array
    {
        $items = [];

        foreach (explode(',', $selectors) as $selector) {
            $selector = trim($selector);
            if ($selector !== '') {
                $items[] = $selector;
            }
        }

        return $items;
    }

    /**
     * Merge additional header names into an existing Vary header value,
     * de-duplicating case-insensitively while preserving original casing.
     */
    private function appendVaryHeaders(string $existing, array $headers): string
    {
        $vary = [];

        foreach (explode(',', $existing) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $vary[strtolower($item)] = $item;
            }
        }

        foreach ($headers as $header) {
            $vary[strtolower($header)] = $header;
        }

        return implode(', ', array_values($vary));
    }
}
