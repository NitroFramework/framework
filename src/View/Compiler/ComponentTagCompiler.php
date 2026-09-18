<?php

namespace Nitro\View\Compiler;

use Nitro\View\Contracts\TagCompiler;

/**
 * Rewrites component tags into the directives that render them.
 *
 * Runs before the Blade compiler, so that compiler only ever sees directives.
 * Emits calls the renderer answers: startComponent, endComponent,
 * renderComponent, startNamedSlot, endNamedSlot.
 */
class ComponentTagCompiler implements TagCompiler
{
    /**
     * Attributes bound by expression rather than literal value, for the tag
     * currently being compiled.
     *
     * @var array<string, bool>
     */
    protected array $boundAttributes = [];

    /**
     * Rewrite every component tag in the source.
     *
     * Repeats until a pass changes nothing, since nesting cannot be matched in
     * one pass.
     */
    public function compile(string $value): string
    {
        while (str_contains($value, '<x-') || str_contains($value, '<x:')) {
            $original = $value;

            $value = $this->compileSlots($value);
            $value = $this->compileSelfClosingTags($value);
            $value = $this->compileOpeningTags($value);
            $value = $this->compileClosingTags($value);

            if ($original === $value) {
                break;
            }
        }

        $this->assertBalanced($value, 'final');

        return $value;
    }

    // ─── Tag compilation ──────────────────────────────────

    /**
     * `<x-alert type="error">` — open a component and begin capturing its body.
     *
     * The negative lookbehind is what keeps this from claiming a self-closing
     * tag, which {@see compileSelfClosingTags()} owns.
     */
    protected function compileOpeningTags(string $value): string
    {
        $pattern = "/(<)\s*x[-\:]([\w\-\:\.]*)(?<attributes>(?:\s+(?:(?:@(?:class)(\((?>(?:[^()]+)|(?-1))*\)))|(?:@(?:style)(\((?>(?:[^()]+)|(?-1))*\)))|(?:\{\{\s*\\\$attributes(?:[^}]+?)?\s*\}\})|(?:(\:\\\$)(\w+))|(?:[\w\-:.@%]+(=(?:\\\"[^\\\"]*\\\"|\'[^\']*\'|[^\'\\\"=<>]+))?)))*\s*)(?<![\/=\-])>/x";

        return preg_replace_callback($pattern, function (array $matches) {
            $this->boundAttributes = [];

            $component  = $matches[2];
            $attributes = $this->getAttributesFromAttributeString($matches['attributes']);
            $attrString = $this->attributesToPhpArray($attributes);

            return "<?php \$this->startComponent('{$component}', {$attrString}); ?>";
        }, $value);
    }

    /**
     * `<x-icon name="check" />` — render a component with no body.
     *
     * Compiled before opening tags, so a self-closing tag is never mistaken for
     * one that opens a block it will never close.
     */
    protected function compileSelfClosingTags(string $value): string
    {
        $pattern = "/(<)\s*x[-\:]([\w\-\:\.]*)\s*(?<attributes>(?:\s+(?:(?:@(?:class)(\((?>(?:[^()]+)|(?-1))*\)))|(?:@(?:style)(\((?>(?:[^()]+)|(?-1))*\)))|(?:\{\{\s*\\\$attributes(?:[^}]+?)?\s*\}\})|(?:(\:\\\$)(\w+))|(?:[\w\-:.@%]+(=(?:\\\"[^\\\"]*\\\"|\'[^\']*\'|[^\'\\\"=<>]+))?)))*\s*)\/>/x";

        return preg_replace_callback($pattern, function (array $matches) {
            $this->boundAttributes = [];

            $component  = $matches[2];
            $attributes = $this->getAttributesFromAttributeString($matches['attributes']);
            $attrString = $this->attributesToPhpArray($attributes);

            return "<?php \$this->renderComponent('{$component}', {$attrString}); ?>";
        }, $value);
    }

    /**
     * `</x-alert>` — close a component and emit what it rendered.
     */
    protected function compileClosingTags(string $value): string
    {
        return preg_replace(
            "/<\/\s*x[-\:][\w\-\:\.]*\s*>/",
            '<?php echo $this->endComponent(); ?>',
            $value
        );
    }

    /**
     * `<x-slot:title>`, `<x-slot name="title">` or `<x-slot :name="$key">`.
     *
     * An inline name is a literal and is quoted; a bound one is an expression
     * and is emitted raw. A template reads a slot as a variable, and
     * `$page-header` is not one, so an inline kebab-case name becomes camelCase.
     */
    public function compileSlots(string $value): string
    {
        $pattern = "/(<)\s*x[\-\:]slot(?:\:(?<inlineName>\w+(?:-\w+)*))?(?:\s+name=(?<name>(\"[^\"]+\"|\\\'[^\\\']+\\\'|[^\s>]+)))?(?:\s+\:name=(?<boundName>(\"[^\"]+\"|\\\'[^\\\']+\\\'|[^\s>]+)))?(?<attributes>(?:\s+(?:(?:@(?:class)(\((?>(?:[^()]+)|(?-1))*\)))|(?:@(?:style)(\((?>(?:[^()]+)|(?-1))*\)))|(?:\{\{\s*\\\$attributes(?:[^}]+?)?\s*\}\})|(?:[\w\-:.@]+(=(?:\\\"[^\\\"]*\\\"|\'[^\']*\'|[^\'\\\"=<>]+))?)))*\s*)(?<![\/=\-])>/x";

        $value = preg_replace_callback($pattern, function ($matches) {
            $name = $this->stripQuotes(
                $matches['inlineName'] ?: $matches['name'] ?: $matches['boundName'] ?: ''
            );

            if ($name === '') {
                $name = 'slot';
            }
            if (str_contains($name, '-') && ! empty($matches['inlineName'])) {
                $name = $this->kebabToCamel($name);
            }

            if (! empty($matches['inlineName']) || ! empty($matches['name'])) {
                return "<?php \$this->startNamedSlot('{$name}'); ?>";
            }

            return "<?php \$this->startNamedSlot({$name}); ?>";
        }, $value);

        return preg_replace('/<\/\s*x[\-\:]slot[^>]*>/', '<?php $this->endNamedSlot(); ?>', $value);
    }

    // ─── Attribute parsing ────────────────────────────────

    /**
     * Parse an attribute string into key => value pairs.
     */
    protected function getAttributesFromAttributeString(string $attributeString): array
    {
        $attributeString = $this->parseShortAttributeSyntax($attributeString);
        $attributeString = $this->parseAttributeBag($attributeString);
        $attributeString = $this->parseComponentTagClassStatements($attributeString);
        $attributeString = $this->parseComponentTagStyleStatements($attributeString);
        $attributeString = $this->parseBindAttributes($attributeString);

        $pattern = '/
            (?<attribute>[\w\-:.@%]+)
            (
                =
                (?<value>
                    (
                        "[^"]+"
                        |
                        \\\'[^\\\']+\\\'
                        |
                        [^\s>]+
                    )
                )
            )?
        /x';

        if (!preg_match_all($pattern, $attributeString, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $attributes = [];

        foreach ($matches as $match) {
            $attribute = $match['attribute'];
            $value     = $match['value'] ?? null;

            if (is_null($value)) {
                $value     = 'true';
                $attribute = 'bind:' . $attribute;
            }

            $value = $this->stripQuotes($value);

            if (str_starts_with($attribute, 'bind:')) {
                $attribute = substr($attribute, 5);
                $this->boundAttributes[$attribute] = true;
            } else {
                $value = "'" . $this->compileAttributeEchos($value) . "'";
            }

            if (str_starts_with($attribute, '::')) {
                $attribute = substr($attribute, 1);
            }

            $attributes[$attribute] = $value;
        }

        return $attributes;
    }

    /**
     * :$foo → :foo="$foo"
     */
    protected function parseShortAttributeSyntax(string $value): string
    {
        return preg_replace_callback("/\s\:\\\$(\w+)/x", function (array $matches) {
            return " :{$matches[1]}=\"\${$matches[1]}\"";
        }, $value);
    }

    /**
     * {{ $attributes }} → :attributes="$attributes"
     */
    protected function parseAttributeBag(string $attributeString): string
    {
        $pattern = "/
            (?:^|\s+)
            \{\{\s*(\\\$attributes(?:[^}]+?(?<!\s))?)\s*\}\}
        /x";

        return preg_replace($pattern, ' :attributes="$1"', $attributeString);
    }

    /**
     * @class(['foo' => true]) → :class="..."
     */
    protected function parseComponentTagClassStatements(string $attributeString): string
    {
        return preg_replace_callback(
            '/@(class)(\( ( (?>[^()]+) | (?2) )* \))/x',
            function ($match) {
                $match[2] = str_replace('"', "'", $match[2]);
                return ":class=\"\\Nitro\\Support\\Arr::toCssClasses{$match[2]}\"";
            },
            $attributeString
        );
    }

    /**
     * @style(['color: red' => true]) → :style="..."
     */
    protected function parseComponentTagStyleStatements(string $attributeString): string
    {
        return preg_replace_callback(
            '/@(style)(\( ( (?>[^()]+) | (?2) )* \))/x',
            function ($match) {
                $match[2] = str_replace('"', "'", $match[2]);
                return ":style=\"\\Nitro\\Support\\Arr::toCssStyles{$match[2]}\"";
            },
            $attributeString
        );
    }

    /**
     * :foo="bar" → bind:foo="bar"
     */
    protected function parseBindAttributes(string $attributeString): string
    {
        $pattern = "/
            (?:^|\s+)
            :(?!:)
            ([\w\-:.@]+)
            =
        /xm";

        return preg_replace($pattern, ' bind:$1=', $attributeString);
    }

    /**
     * Compile Blade echoes inside an attribute value into concatenation.
     *
     * `{!! … !!}` interpolates raw, `{{ … }}` escapes, matching what the same
     * syntax does in a template body.
     */
    protected function compileAttributeEchos(string $attributeString): string
    {
        $value = preg_replace(
            '/\{\!!\s*(.+?)\s*!!\}/',
            "' . (\$1) . '",
            $attributeString
        );

        $value = preg_replace(
            '/\{\{\s*(.+?)\s*\}\}/',
            "' . e(\$1) . '",
            $value
        );

        return $value;
    }

    // ─── Output helpers ───────────────────────────────────

    /**
     * Convert parsed attributes to a PHP array string.
     *
     * Bound attributes (from :attr or bind:attr) are kept as raw PHP expressions.
     * Static attributes are wrapped in quotes.
     */
    protected function attributesToPhpArray(array $attributes): string
    {
        if (empty($attributes)) {
            return '[]';
        }

        $parts = [];

        foreach ($attributes as $key => $value) {
            $escapedKey = str_replace("'", "\\'", $key);
            $parts[]    = "'{$escapedKey}' => {$value}";
        }

        return '[' . implode(', ', $parts) . ']';
    }

    // ─── Validation ───────────────────────────────────────

    /**
     * Fail loudly when a compilation pass leaves brackets unbalanced.
     *
     * @throws \RuntimeException When the pass produced malformed output.
     */
    protected function assertBalanced(string $value, string $phase): void
    {
        $stack = [];

        $pattern = '/(renderComponent|startComponent|startNamedSlot|endComponent|endNamedSlot)/';
        preg_match_all($pattern, $value, $matches);

        foreach ($matches[0] as $token) {
            if ($token === 'renderComponent') {
                continue;
            }

            if (str_starts_with($token, 'start')) {
                $stack[] = str_replace('start', '', $token);
            } else {
                $type = str_replace('end', '', $token);

                if (empty($stack)) {
                    throw new \RuntimeException(
                        "Nitro Error [{$phase}]: Unexpected {$token}. No matching start tag found."
                    );
                }

                $last = array_pop($stack);
                if ($last !== $type) {
                    throw new \RuntimeException(
                        "Nitro Error [{$phase}]: Mismatched tags. Expected end{$last}, but found {$token}."
                    );
                }
            }
        }

        if (!empty($stack)) {
            $unclosed = array_pop($stack);
            throw new \RuntimeException(
                "Nitro Error [{$phase}]: Unbalanced template. A '{$unclosed}' was never closed."
            );
        }
    }

    // ─── String helpers ───────────────────────────────────

    /** Strip one layer of matching quotes from an attribute value. */
    protected function stripQuotes(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[strlen($value) - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }

    /**
     * kebab-case → camelCase
     */
    protected function kebabToCamel(string $value): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $value))));
    }
}
