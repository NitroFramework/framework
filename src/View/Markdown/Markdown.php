<?php

namespace Nitro\View\Markdown;

use Nitro\Container\Container;
use Nitro\View\Contracts\MarkdownParser;

/**
 * What a compiled Markdown template calls at render time.
 *
 * The compiler cannot emit a container lookup that reads well, and a template
 * compiled under one binding may be served under another, so the parser is
 * resolved per render rather than baked into the compiled file.
 */
final class Markdown
{
    /**
     * Convert a rendered Markdown body into HTML.
     */
    public static function render(string $markdown): string
    {
        return self::parser()->toHtml($markdown);
    }

    /**
     * The bound parser, or a default one when there is no container to ask —
     * which is how a template still renders in a test that never booted.
     */
    private static function parser(): MarkdownParser
    {
        if (! Container::hasInstance()) {
            return new Parser();
        }

        $container = Container::getInstance();

        return $container->has(MarkdownParser::class)
            ? $container->resolve(MarkdownParser::class)
            : new Parser();
    }
}
