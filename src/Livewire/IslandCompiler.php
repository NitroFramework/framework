<?php

namespace Nitro\Livewire;

/**
 * Compiles @island('name', ...)…@endisland blocks (with an optional
 * @placeholder…@endplaceholder inside) into a call to the component's island()
 * method. The island body is wrapped in a DEFERRED closure, so when an island
 * is frozen (skipped on a re-render) its body is never executed — that is the
 * real saving over regions, which always render everything.
 *
 * Registered as a Blade precompiler; runs on raw source before core compilation,
 * and the innermost islands are peeled first so nesting works.
 */
class IslandCompiler
{
    /** Rewrite every @island block in the template. */
    public static function compile(string $template): string
    {
        if (! str_contains($template, '@island')) {
            return $template;
        }

        // Match innermost islands first (body contains no nested @island), loop out.
        $pattern = '/@island\s*\((.*?)\)((?:(?!@island).)*?)@endisland/s';

        $previous = null;
        while ($template !== $previous && str_contains($template, '@island')) {
            $previous = $template;
            $template = preg_replace_callback($pattern, [self::class, 'compileBlock'], $template);
        }

        return $template;
    }

    /** Compile one @island(...)…@endisland block. */
    protected static function compileBlock(array $m): string
    {
        [$name, $options] = self::parseArguments($m[1]);
        [$body, $placeholder] = self::extractPlaceholder($m[2]);

        $bodyClosure = self::closure($body);
        $placeholderArg = $placeholder !== null ? ', ' . self::closure($placeholder) : ', null';

        // Guard so @island in a non-component view degrades gracefully.
        return '<?php if (isset($this) && $this instanceof \\Nitro\\Livewire\\Component): '
            . '$__islandScope = get_defined_vars(); '
            . "echo \$this->island({$name}, {$options}, \$__islandScope, "
            . $bodyClosure . $placeholderArg . '); endif; ?>';
    }

    /** Wrap captured template content in a deferred, scope-extracting closure. */
    protected static function closure(string $content): string
    {
        return 'function ($__s) { extract($__s, EXTR_SKIP); ob_start(); ?>'
            . $content
            . '<?php return ob_get_clean(); }';
    }

    /** Split an island body into [body, placeholder] on an @placeholder block. */
    protected static function extractPlaceholder(string $inner): array
    {
        if (preg_match('/(.*?)@placeholder(.*?)@endplaceholder(.*)/s', $inner, $m)) {
            return [$m[1] . $m[3], $m[2]];
        }

        return [$inner, null];
    }

    /**
     * Parse the directive arguments into [nameExpr, optionsArrayLiteral].
     * Options use named-argument syntax: @island('x', lazy: true, with: [...]).
     */
    protected static function parseArguments(string $expression): array
    {
        [$name, $rest] = self::splitFirstComma(trim($expression));

        $rest = trim($rest);
        $options = $rest === ''
            ? '[]'
            : '[' . preg_replace('/\b(lazy|defer|always|skip|with|name)\s*:/', "'$1' =>", $rest) . ']';

        return [trim($name), $options];
    }

    /** Split an expression on its first top-level comma (respecting quotes/brackets). */
    protected static function splitFirstComma(string $expression): array
    {
        $depth = 0;
        $quote = null;
        $length = strlen($expression);

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];

            if ($quote !== null) {
                if ($char === $quote && ($i === 0 || $expression[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                return [substr($expression, 0, $i), substr($expression, $i + 1)];
            }
        }

        return [$expression, ''];
    }
}
