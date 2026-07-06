<?php

namespace Nitro\Livewire;

/**
 * Expands the <livewire:name ... /> tag form into the mount call the layer
 * already understands, so a component can be dropped into a Blade view as a tag
 * instead of the @livewire('name', [...]) directive. Registered as a Blade
 * precompiler, it runs on raw template source before any other compilation
 * pass. Attribute forms mirror Blade components:
 *
 *   <livewire:counter />                     → mount('counter', [])
 *   <livewire:user-card :user="$user" />     → mount('user-card', ['user' => $user])
 *
 * Paired tags may carry slot content that is rendered in the parent's context
 * and passed to the child, which reads it via $slot / $slots['name']:
 *
 *   <livewire:panel>
 *       <livewire:slot name="title">Reports</livewire:slot>
 *       Body content
 *   </livewire:panel>
 */
class LivewireTagCompiler
{
    /** Rewrite every <livewire:*> tag in the given template source. */
    public static function compile(string $template): string
    {
        if (! str_contains($template, '<livewire:')) {
            return $template;
        }

        // Loop so a component nested inside a slot is peeled on a later pass.
        $previous = null;
        while ($template !== $previous && str_contains($template, '<livewire:')) {
            $previous = $template;
            $template = self::compilePaired($template);
            $template = self::compileSelfClosing($template);
        }

        return $template;
    }

    /** Compile <livewire:name …>…</livewire:name>, capturing any slot content. */
    protected static function compilePaired(string $template): string
    {
        $pattern = '/<livewire:([\w.\-]+)\b([^>]*?)>(.*?)<\/livewire:\1>/s';

        return preg_replace_callback($pattern, static function (array $m): string {
            $name = $m[1];
            if ($name === 'slot') {
                return $m[0]; // handled inside its parent, not on its own
            }

            $params = self::attributesToArray($m[2] ?? '');
            [$named, $default] = self::extractSlots($m[3] ?? '');

            // No content → a plain paired tag, same as the self-closing form.
            if ($named === [] && trim($default) === '') {
                return "<?php echo app('livewire')->mount('{$name}', {$params}); ?>";
            }

            // Capture each slot's rendered HTML in the parent's context, then mount.
            $php = "<?php \$__slotStack[] = \$__slots ?? []; \$__slots = []; ?>";
            foreach ($named as $slotName => $content) {
                $key = addslashes($slotName);
                $php .= "<?php ob_start(); ?>" . $content
                    . "<?php \$__slots['{$key}'] = new \\Nitro\\Livewire\\Slot(ob_get_clean()); ?>";
            }
            $php .= "<?php ob_start(); ?>" . $default
                . "<?php \$__slots['default'] = new \\Nitro\\Livewire\\Slot(trim(ob_get_clean())); ?>";
            $php .= "<?php echo app('livewire')->mount('{$name}', {$params}, \$__slots); "
                . "\$__slots = array_pop(\$__slotStack); ?>";

            return $php;
        }, $template);
    }

    /** Compile <livewire:name … /> self-closing tags. */
    protected static function compileSelfClosing(string $template): string
    {
        $pattern = '/<livewire:([\w.\-]+)\b([^>]*?)\/>/s';

        return preg_replace_callback($pattern, static function (array $m): string {
            $name = $m[1];
            if ($name === 'slot') {
                return $m[0];
            }

            $params = self::attributesToArray($m[2] ?? '');

            return "<?php echo app('livewire')->mount('{$name}', {$params}); ?>";
        }, $template);
    }

    /**
     * Pull <livewire:slot name="x">…</livewire:slot> blocks out of a parent tag's
     * inner content, returning [namedSlots, remainingDefaultContent].
     *
     * @return array{0: array<string, string>, 1: string}
     */
    protected static function extractSlots(string $inner): array
    {
        $named = [];
        $pattern = '/<livewire:slot\s+name=(?:"([^"]+)"|\'([^\']+)\')\s*>(.*?)<\/livewire:slot>/s';

        $default = preg_replace_callback($pattern, static function (array $m) use (&$named): string {
            $slotName = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
            $named[$slotName] = $m[3];

            return '';
        }, $inner);

        return [$named, $default ?? $inner];
    }

    /**
     * Turn a tag's attribute string into a PHP array literal. Bound attributes
     * (:prop="$expr") pass through as raw expressions; plain attributes become
     * quoted strings; structural attributes (wire:key) are dropped.
     */
    protected static function attributesToArray(string $attributeString): string
    {
        if (trim($attributeString) === '') {
            return '[]';
        }

        preg_match_all(
            '/([:@]?[\w:.\-]+)(?:=(?:"([^"]*)"|\'([^\']*)\'))?/',
            $attributeString,
            $matches,
            PREG_SET_ORDER
        );

        $parts = [];

        foreach ($matches as $match) {
            $name = $match[1];
            if ($name === '') {
                continue;
            }

            $hasValue = isset($match[2]) || isset($match[3]);
            $value = $match[2] ?? $match[3] ?? '';

            if (str_starts_with($name, ':')) {
                // Bound attribute — the value is a PHP expression.
                $key = substr($name, 1);
                $parts[] = "'{$key}' => {$value}";
            } elseif (str_starts_with($name, 'wire:') || $name === 'key') {
                // Structural, not a mount parameter.
                continue;
            } elseif (! $hasValue) {
                $parts[] = "'{$name}' => true";
            } else {
                $parts[] = "'{$name}' => '" . addcslashes($value, "'\\") . "'";
            }
        }

        return '[' . implode(', ', $parts) . ']';
    }
}
