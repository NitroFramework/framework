<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Compiles the directives that stand in for a line of PHP: forms, assets,
 * URLs, conditional attributes and the debugging aids.
 */
trait CompilesHelpers
{
    // ─── Forms ────────────────────────────────────────────

    /**
     * `@csrf` — the hidden field proving a form came from this site.
     */
    protected function compileCsrf(string $args): string
    {
        return '<?php echo csrf_field(); ?>';
    }

    /**
     * `@method('PUT')` — the hidden field that lets an HTML form, which can
     * only send GET and POST, stand in for another verb.
     */
    protected function compileMethod(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo '<input type=\"hidden\" name=\"_method\" value=\"' . htmlspecialchars({$expression}, ENT_QUOTES, 'UTF-8') . '\">'; ?>";
    }

    // ─── Data ─────────────────────────────────────────────

    /**
     * `@json($data)` or `@json($data, JSON_PRETTY_PRINT)` — a value as JSON,
     * for handing server state to a script.
     */
    protected function compileJson(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo json_encode({$expression}); ?>";
    }

    // ─── Debugging ────────────────────────────────────────

    /**
     * `@dump($value)` — render a value's structure without stopping the page.
     *
     * A trailing integer is read as the dumper's depth limit rather than as
     * another value to dump, which is what {@see splitLastArgument()} decides.
     */
    protected function compileDump(string $args): string
    {
        $expression = $this->stripParentheses($args);
        $parts = $this->splitLastArgument($expression);

        if ($parts) {
            return "<?php (new \Nitro\Debug\Dumper({$parts['last']}))->dump({$parts['rest']}); ?>";
        }

        return "<?php (new \Nitro\Debug\Dumper())->dump({$expression}); ?>";
    }

    /**
     * `@dd($value)` — dump and stop, for when what follows would only get in
     * the way of reading the dump.
     */
    protected function compileDd(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php (new \Nitro\Debug\Dumper())->dump({$expression}); exit(1); ?>";
    }

    /**
     * `@trace` or `@trace('a label')` — the call stack, printed where it stands.
     *
     * Taken during the render, so the frames run past the controller and
     * through the view engine to this template — which a trace taken in
     * the controller cannot show, the view not having happened yet.
     */
    protected function compileTrace(string $args): string
    {
        $expression = $this->stripParentheses($args);
        $label = $expression === '' ? 'null' : $expression;

        return "<?php echo trace_renderer({$label})->toHtml(); ?>";
    }

    /**
     * `@dt` or `@dt('a label')` — the same, then stop.
     *
     * For a template whose later output is what you are trying to get
     * past, the way `@dd` stops on a value.
     */
    protected function compileDt(string $args): string
    {
        $expression = $this->stripParentheses($args);
        $label = $expression === '' ? 'null' : $expression;

        return "<?php echo trace_renderer({$label})->toHtml(); exit(1); ?>";
    }

    /**
     * `@rawdump($value)` — PHP's own `var_dump`, for when the formatted dumper
     * is itself what is in question.
     */
    protected function compileRawdump(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php var_dump({$expression}); ?>";
    }

    /**
     * Split a trailing integer argument off an expression, or null when absent.
     *
     * @return array{rest: string, last: string}|null
     */
    private function splitLastArgument(string $expression): ?array
    {
        $depth = 0;
        $lastComma = null;

        for ($i = 0; $i < strlen($expression); $i++) {
            $char = $expression[$i];

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $lastComma = $i;
            }
        }

        if ($lastComma === null) {
            return null;
        }

        $rest = trim(substr($expression, 0, $lastComma));
        $last = trim(substr($expression, $lastComma + 1));

        if (! ctype_digit($last)) {
            return null;
        }

        return ['rest' => $rest, 'last' => $last];
    }

    // ─── Assets and URLs ──────────────────────────────────

    /**
     * `@asset('css/app.css')` — a root-relative path, however the caller wrote
     * the leading slash.
     */
    protected function compileAsset(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo '/' . ltrim({$expression}, '/'); ?>";
    }

    /**
     * `@vite('resources/css/app.css')` or `@vite([...])`.
     *
     * Emits the dev server's tags while it is running, the built output
     * otherwise.
     */
    protected function compileVite(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo app(\\Nitro\\View\\Vite::class)->tags({$expression}); ?>";
    }

    /**
     * `@url(...)` — echo an already-built URL expression.
     */
    protected function compileUrl(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo {$expression}; ?>";
    }

    /**
     * `@route(...)` — echo an already-built route expression.
     */
    protected function compileRoute(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo {$expression}; ?>";
    }

    // ─── Conditional attributes ───────────────────────────

    /**
     * `@checked($condition)` — emit the attribute only when it applies.
     *
     * A boolean attribute is true by its presence, so the word must be absent
     * rather than set to a falsy value.
     */
    protected function compileChecked(string $args): string
    {
        return "<?php if{$args}: echo 'checked'; endif; ?>";
    }

    /**
     * `@selected($condition)` — as {@see compileChecked()}, for `<option>`.
     */
    protected function compileSelected(string $args): string
    {
        return "<?php if{$args}: echo 'selected'; endif; ?>";
    }

    /**
     * `@disabled($condition)` — as {@see compileChecked()}.
     */
    protected function compileDisabled(string $args): string
    {
        return "<?php if{$args}: echo 'disabled'; endif; ?>";
    }

    /**
     * `@readonly($condition)` — as {@see compileChecked()}.
     */
    protected function compileReadonly(string $args): string
    {
        return "<?php if{$args}: echo 'readonly'; endif; ?>";
    }

    /**
     * `@required($condition)` — as {@see compileChecked()}.
     */
    protected function compileRequired(string $args): string
    {
        return "<?php if{$args}: echo 'required'; endif; ?>";
    }

    /**
     * `@class(['a', 'b' => $condition])` — a class attribute, omitted entirely
     * when it resolves to nothing.
     */
    protected function compileClass(string $args): string
    {
        $expression = ! empty($args) ? $args : '([])';

        return "class=\"<?php echo \$this->toCssClasses{$expression}; ?>\"";
    }

    /**
     * `@style(['color: red' => $condition])` — an inline style attribute.
     */
    protected function compileStyle(string $args): string
    {
        $expression = ! empty($args) ? $args : '([])';

        return "style=\"<?php echo \$this->toCssStyles{$expression}; ?>\"";
    }
}
