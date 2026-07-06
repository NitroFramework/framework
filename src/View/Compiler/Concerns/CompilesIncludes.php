<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: @include and related partial directives.
 */
trait CompilesIncludes
{
    /**
     * Compile @include into one of two shapes depending on call form:
     *
     *   @include('header')                 — caller wants parent scope; emit
     *                                        get_defined_vars() so vars carry over.
     *   @include('header', ['k' => $v])    — caller passed explicit data; skip
     *                                        get_defined_vars() entirely (it
     *                                        walks the whole local symbol
     *                                        table on every call).
     *
     * Distinguishing the two at COMPILE time means a page that uses the
     * explicit form for all its includes pays zero get_defined_vars() cost
     * at runtime — a measurable win on pages with many includes.
     */
    protected function compileInclude(string $args): string
    {
        $expression = $this->stripParentheses($args);

        if ($this->hasExplicitDataArg($expression)) {
            return "<?php echo \$this->renderPartial({$expression}); ?>";
        }

        return "<?php echo \$this->renderInclude({$expression}, get_defined_vars()); ?>";
    }

    protected function compileIncludeIf(string $args): string
    {
        $expression = $this->stripParentheses($args);

        if ($this->hasExplicitDataArg($expression)) {
            return "<?php if(\$this->viewExists(" . $this->firstArgOf($expression)
                . ")) echo \$this->renderPartial({$expression}); ?>";
        }

        return "<?php if(\$this->viewExists({$expression})) echo \$this->renderInclude({$expression}, get_defined_vars()); ?>";
    }

    protected function compileIncludeWhen(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo \$this->renderIncludeWhen({$expression}, get_defined_vars()); ?>";
    }

    protected function compileIncludeUnless(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo \$this->renderIncludeUnless({$expression}, get_defined_vars()); ?>";
    }

    protected function compileIncludeFirst(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo \$this->renderIncludeFirst({$expression}, get_defined_vars()); ?>";
    }

    protected function compileEach(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo \$this->renderEach({$expression}); ?>";
    }

    /**
     * Does the argument list contain a second argument? We do a depth-aware
     * scan for a top-level comma so commas inside the view name's string
     * literal or inside nested function calls don't trick us.
     *
     * Returns true for `'header', ['k' => 1]`, false for `'header'`.
     */
    private function hasExplicitDataArg(string $expression): bool
    {
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $len = strlen($expression);

        for ($i = 0; $i < $len; $i++) {
            $c = $expression[$i];

            if ($inSingle) {
                if ($c === '\\' && $i + 1 < $len) { $i++; continue; }
                if ($c === "'") $inSingle = false;
                continue;
            }
            if ($inDouble) {
                if ($c === '\\' && $i + 1 < $len) { $i++; continue; }
                if ($c === '"') $inDouble = false;
                continue;
            }

            if ($c === "'") { $inSingle = true; continue; }
            if ($c === '"') { $inDouble = true; continue; }
            if ($c === '(' || $c === '[' || $c === '{') { $depth++; continue; }
            if ($c === ')' || $c === ']' || $c === '}') { $depth--; continue; }

            if ($c === ',' && $depth === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Slice the first comma-separated argument off the front. Used by
     * compileIncludeIf so the existence check sees just the view name.
     */
    private function firstArgOf(string $expression): string
    {
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $len = strlen($expression);

        for ($i = 0; $i < $len; $i++) {
            $c = $expression[$i];

            if ($inSingle) {
                if ($c === '\\' && $i + 1 < $len) { $i++; continue; }
                if ($c === "'") $inSingle = false;
                continue;
            }
            if ($inDouble) {
                if ($c === '\\' && $i + 1 < $len) { $i++; continue; }
                if ($c === '"') $inDouble = false;
                continue;
            }

            if ($c === "'") { $inSingle = true; continue; }
            if ($c === '"') { $inDouble = true; continue; }
            if ($c === '(' || $c === '[' || $c === '{') { $depth++; continue; }
            if ($c === ')' || $c === ']' || $c === '}') { $depth--; continue; }

            if ($c === ',' && $depth === 0) {
                return trim(substr($expression, 0, $i));
            }
        }

        return $expression;
    }
}
