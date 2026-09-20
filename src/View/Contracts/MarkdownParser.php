<?php

namespace Nitro\View\Contracts;

/**
 * Converts Markdown source into HTML.
 *
 * The seam that lets an application swap the bundled parser for another one
 * without the view layer knowing which is in place.
 */
interface MarkdownParser
{
    /**
     * Convert Markdown source into an HTML fragment.
     *
     * @param  string $markdown
     * @return string
     */
    public function toHtml(string $markdown): string;
}
