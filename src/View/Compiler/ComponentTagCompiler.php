<?php

namespace Nitro\View\Compiler;

use Nitro\View\Contracts\TagCompiler;

/**
 * ComponentTagCompiler — adapted from Laravel's Illuminate\View\Compilers\ComponentTagCompiler.
 *
 * Uses Laravel's proven regex patterns for parsing <x-component> tags, <x-slot> tags,
 * and all attribute variations (:bind, @class, @style, {{ $attributes }}, :$shorthand).
 *
 * Output is wired to Nitro's ComponentRenderer lifecycle:
 *   - $this->startComponent($name, $attributes)
 *   - $this->endComponent()
 *   - $this->renderComponent($name, $attributes)  [self-closing]
 *   - $this->startNamedSlot($name)
 *   - $this->endNamedSlot()
 */
class ComponentTagCompiler implements TagCompiler
{
    /**
     * The "bind:" attributes compiled for the current component.
     */
    protected array $boundAttributes = [];

    /**
     * Entry point — loop-until-clean strategy for infinite nesting.
     * Each pass peels one layer of <x-*> tags from the outside in.
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

    // ──────────────────────────────────────────────────────────────
    //  Tag Compilation (regex from Laravel)
    // ──────────────────────────────────────────────────────────────

    /**
     * Compile opening <x-component> tags.
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
     * Compile self-closing <x-component /> tags.
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
     * Compile closing </x-component> tags.
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
     * Compile <x-slot> tags (opening + closing).
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

            // kebab-case → camelCase for inline names
            if (str_contains($name, '-') && !empty($matches['inlineName'])) {
                $name = $this->kebabToCamel($name);
            }

            // Inline name = static string, wrap in quotes
            // Bound name (:name="$var") = PHP expression, leave raw
            if (!empty($matches['inlineName']) || !empty($matches['name'])) {
                return "<?php \$this->startNamedSlot('{$name}'); ?>";
            }

            // Bound name — dynamic (rare, but supported)
            return "<?php \$this->startNamedSlot({$name}); ?>";
        }, $value);

        return preg_replace('/<\/\s*x[\-\:]slot[^>]*>/', '<?php $this->endNamedSlot(); ?>', $value);
    }

    // ──────────────────────────────────────────────────────────────
    //  Attribute Parsing (from Laravel, zero Illuminate deps)
    // ──────────────────────────────────────────────────────────────

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
     * Compile Blade echo statements {{ }} inside attribute values
     * into string concatenation for PHP.
     */
    protected function compileAttributeEchos(string $attributeString): string
    {
        // {!! $var !!} → raw echo
        $value = preg_replace(
            '/\{\!!\s*(.+?)\s*!!\}/',
            "' . (\$1) . '",
            $attributeString
        );

        // {{ $var }} → escaped echo
        $value = preg_replace(
            '/\{\{\s*(.+?)\s*\}\}/',
            "' . e(\$1) . '",
            $value
        );

        return $value;
    }

    // ──────────────────────────────────────────────────────────────
    //  Output Helpers
    // ──────────────────────────────────────────────────────────────

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

    // ──────────────────────────────────────────────────────────────
    //  Validation
    // ──────────────────────────────────────────────────────────────

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

    // ──────────────────────────────────────────────────────────────
    //  String Helpers (inlined, no Illuminate\Support\Str needed)
    // ──────────────────────────────────────────────────────────────

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