<?php

namespace Nitro\Fusion\Compiler;

use Nitro\Fusion\JsTranspile\JsTranspiler;

/**
 * Compiles a Fusion component's Blade view (a reactive subset) into a client-side
 * JS render function. Nitro's own Blade engine still renders it server-side for
 * first paint; this produces the browser counterpart that re-renders reactively.
 *
 * The reactive subset handled here (v1):
 *   - `{{ $expr }}`   → HTML-escaped interpolation (expression transpiled to JS)
 *   - `{!! $expr !!}` → raw interpolation
 *   - `fusion:click|submit|change|input|... = "method"` → delegated event bindings
 *   - `fusion:model = "$prop"` → two-way bound props
 *
 * The emitted render function destructures the component's public props and
 * aliases `$this`, so a `{{ $count }}` (→ `count`) and a `{{ $this->total() }}`
 * (→ `$this.total()`) both resolve against the live component instance.
 *
 * Heavier Blade (arbitrary @php, side-effecty echoes, unsupported directives) is
 * out of scope and belongs in server-rendered components — see the design's
 * "reactive Blade subset".
 */
class BladeCompiler
{
    /**
     * @param string   $template    The Blade view source.
     * @param string[] $publicProps The component's public prop names (from the
     *                               ComponentTranspiler), destructured in render.
     */
    public function compile(string $template, array $publicProps = []): CompiledTemplate
    {
        $events = [];
        $models = [];

        // fusion:model[.modifier]="$prop" → data-fusion-model
        $html = preg_replace_callback(
            '/\bfusion:model(?:\.[\w.]+)?="\$?(\w+)"/',
            function (array $m) use (&$models): string {
                $models[] = $m[1];
                return 'data-fusion-model="' . $m[1] . '"';
            },
            $template
        );

        // fusion:<event>[.modifier]="method" → data-fusion-<event>
        $html = preg_replace_callback(
            '/\bfusion:(click|submit|change|input|keydown|keyup|blur|focus)(?:\.[\w.]+)?="(\w+)"/',
            function (array $m) use (&$events): string {
                $events[] = ['event' => $m[1], 'method' => $m[2]];
                return 'data-fusion-' . $m[1] . '="' . $m[2] . '"';
            },
            $html
        );

        // Escape the static HTML for a JS template literal BEFORE injecting our
        // ${...} interpolations, so their braces/backticks aren't escaped.
        $html = str_replace(['\\', '`', '${'], ['\\\\', '\\`', '\\${'], $html);

        // {!! raw !!} first (so its inner braces aren't consumed by {{ }})
        $html = preg_replace_callback(
            '/\{!!\s*(.+?)\s*!!\}/s',
            fn (array $m): string => '${' . $this->expr($m[1]) . '}',
            $html
        );
        // {{ escaped }}
        $html = preg_replace_callback(
            '/\{\{\s*(.+?)\s*\}\}/s',
            fn (array $m): string => '${__esc(' . $this->expr($m[1]) . ')}',
            $html
        );

        $destructure = $publicProps !== []
            ? 'const { ' . implode(', ', $publicProps) . ' } = c; '
            : '';

        $js = '(c) => { var $this = c; ' . $destructure . 'return `' . $html . '`; }';

        return new CompiledTemplate($js, $events, $models);
    }

    /** Transpile a Blade `{{ }}` expression to JS (bare-name mode; render scopes it). */
    private function expr(string $php): string
    {
        return trim((string) (new JsTranspiler())->convert($php, true, null));
    }
}
