<?php

namespace Nitro\Blaze;

/**
 * The Blade precompiler that turns eligible <x-name …> component tags into
 * direct $__blaze->render() calls before the core tag compiler runs. Only
 * plain-template components Blaze has enabled and whose attributes it fully
 * understands are rewritten; every other tag is left untouched for Nitro's
 * normal component pipeline — so Blaze can never change rendered output, only
 * make it faster.
 */
class BlazeCompiler
{
    protected bool $rewrote = false;

    public function __construct(protected BlazeManager $manager) {}

    /** Rewrite eligible <x-*> tags in a template to Blaze runtime calls. */
    public function compile(string $template): string
    {
        if (! $this->manager->isMasterEnabled() || ! str_contains($template, '<x-')) {
            return $template;
        }

        $this->rewrote = false;

        // Loop so components nested inside slots are peeled on a later pass.
        $previous = null;
        while ($template !== $previous && str_contains($template, '<x-')) {
            $previous = $template;
            $template = $this->compilePaired($template);
            $template = $this->compileSelfClosing($template);
        }

        if ($this->rewrote) {
            $template = "<?php \$__blaze = \$__blaze ?? app(\\Nitro\\Blaze\\BlazeRuntime::class); ?>" . $template;
        }

        return $template;
    }

    /** <x-name … /> */
    protected function compileSelfClosing(string $template): string
    {
        return preg_replace_callback(
            '/<x-([\w.\-]+)((?:\s+[^>]*?)?)\s*\/>/s',
            function (array $m): string {
                [$ok, $name, $params] = $this->prepare($m[1], $m[2]);
                if (! $ok) {
                    return $m[0];
                }

                $this->rewrote = true;

                return "<?php echo \$__blaze->render('{$name}', {$params}); ?>";
            },
            $template
        );
    }

    /** <x-name …>…</x-name> */
    protected function compilePaired(string $template): string
    {
        return preg_replace_callback(
            '/<x-([\w.\-]+)((?:\s+[^>]*?)?)>(.*?)<\/x-\1>/s',
            function (array $m): string {
                [$ok, $name, $params] = $this->prepare($m[1], $m[2]);
                if (! $ok) {
                    return $m[0];
                }

                [$named, $default] = $this->extractSlots($m[3]);

                // Any nested <x-…> in the slots that Blaze can't handle must stay
                // for the core compiler, so bail unless the content is clean.
                if (str_contains($default, '<x-') && ! $this->allNestedEligible($default)) {
                    return $m[0];
                }

                $this->rewrote = true;

                $php = "<?php \$__blazeStack[] = \$__blazeSlots ?? []; \$__blazeSlots = []; ?>";
                foreach ($named as $slotName => $content) {
                    $key = addslashes($slotName);
                    $php .= "<?php ob_start(); ?>" . $content
                        . "<?php \$__blazeSlots['{$key}'] = ob_get_clean(); ?>";
                }
                $php .= "<?php ob_start(); ?>" . $default
                    . "<?php \$__blazeDefault = trim(ob_get_clean()); ?>";
                $php .= "<?php echo \$__blaze->render('{$name}', {$params}, \$__blazeDefault, \$__blazeSlots); "
                    . "\$__blazeSlots = array_pop(\$__blazeStack); ?>";

                return $php;
            },
            $template
        );
    }

    /**
     * Validate a tag: the component must be Blaze-enabled and its attributes
     * simple enough to compile. Returns [eligible, name, phpAttributesArray].
     *
     * @return array{0: bool, 1: string, 2: string}
     */
    protected function prepare(string $name, string $attributeString): array
    {
        if ($name === 'slot' || ! $this->manager->isEnabled($name)) {
            return [false, $name, '[]'];
        }

        // Bail on attribute forms this compiler does not fully model — let core
        // handle them so output is never wrong.
        if (preg_match('/\{\{|\{!!|@class|@style|::|:\$/', $attributeString)) {
            return [false, $name, '[]'];
        }

        return [true, $name, $this->attributesToArray($attributeString)];
    }

    /** Whether every nested <x-…> in some content is itself Blaze-eligible. */
    protected function allNestedEligible(string $content): bool
    {
        if (! preg_match_all('/<x-([\w.\-]+)[\s\/>]/', $content, $m)) {
            return true;
        }

        foreach ($m[1] as $name) {
            if ($name !== 'slot' && ! $this->manager->isEnabled($name)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Pull <x-slot:name>… / <x-slot name="…">… blocks out of a paired tag's
     * content, returning [namedSlots, remainingDefaultContent].
     *
     * @return array{0: array<string, string>, 1: string}
     */
    protected function extractSlots(string $inner): array
    {
        $named = [];
        $pattern = '/<x-slot:([\w\-]+)\s*>(.*?)<\/x-slot:\1>|<x-slot\s+name=(?:"([^"]+)"|\'([^\']+)\')\s*>(.*?)<\/x-slot>/s';

        $default = preg_replace_callback($pattern, function (array $m) use (&$named): string {
            if (($m[1] ?? '') !== '') {
                $named[$m[1]] = $m[2];
            } else {
                $slotName = ($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? '');
                $named[$slotName] = $m[5] ?? '';
            }

            return '';
        }, $inner);

        return [$named, $default ?? $inner];
    }

    /** Turn an attribute string into a PHP array literal (simple forms only). */
    protected function attributesToArray(string $attributeString): string
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
                $parts[] = "'" . substr($name, 1) . "' => {$value}";
            } elseif (! $hasValue) {
                $parts[] = "'{$name}' => true";
            } else {
                $parts[] = "'{$name}' => '" . addcslashes($value, "'\\") . "'";
            }
        }

        return '[' . implode(', ', $parts) . ']';
    }
}
