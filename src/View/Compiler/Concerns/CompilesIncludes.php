<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: @include and related partial directives.
 */
trait CompilesIncludes
{
    /**
     * `@include('header')` or `@include('header', ['k' => $v])`.
     *
     * Only the first form emits get_defined_vars(), which walks the whole local
     * symbol table; telling them apart at compile time keeps that off pages
     * that always pass data explicitly.
     */
    protected function compileInclude(string $args): string
    {
        $expression = $this->stripParentheses($args);

        if ($this->hasExplicitDataArg($expression)) {
            return "<?php echo \$this->renderPartial({$expression}); ?>";
        }

        return "<?php echo \$this->renderInclude({$expression}, get_defined_vars()); ?>";
    }

    /** Compile the `@includeIf` directive, which skips a view that does not exist. */
    protected function compileIncludeIf(string $args): string
    {
        $expression = $this->stripParentheses($args);

        if ($this->hasExplicitDataArg($expression)) {
            return "<?php if(\$this->viewExists(" . $this->firstArgOf($expression)
                . ")) echo \$this->renderPartial({$expression}); ?>";
        }

        return "<?php if(\$this->viewExists({$expression})) echo \$this->renderInclude({$expression}, get_defined_vars()); ?>";
    }

    /** Compile the `@includeWhen` directive. */
    protected function compileIncludeWhen(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo \$this->renderIncludeWhen({$expression}, get_defined_vars()); ?>";
    }

    /** Compile the `@includeUnless` directive. */
    protected function compileIncludeUnless(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo \$this->renderIncludeUnless({$expression}, get_defined_vars()); ?>";
    }

    /** Compile the `@includeFirst` directive, which takes the first view that exists. */
    protected function compileIncludeFirst(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo \$this->renderIncludeFirst({$expression}, get_defined_vars()); ?>";
    }

    /** Compile the `@each` directive. */
    protected function compileEach(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo \$this->renderEach({$expression}); ?>";
    }

    /**
     * Determine whether the argument list has a second argument.
     *
     * Depth-aware, so a comma inside a string or a nested call is not mistaken
     * for a separator.
     */
    private function hasExplicitDataArg(string $expression): bool
    {
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $len = strlen($expression);

        for ($i = 0; $i < $len; $i++) {
            $character = $expression[$i];

            if ($inSingle) {
                if ($character === '\\' && $i + 1 < $len) { $i++; continue; }
                if ($character === "'") $inSingle = false;
                continue;
            }
            if ($inDouble) {
                if ($character === '\\' && $i + 1 < $len) { $i++; continue; }
                if ($character === '"') $inDouble = false;
                continue;
            }

            if ($character === "'") { $inSingle = true; continue; }
            if ($character === '"') { $inDouble = true; continue; }
            if ($character === '(' || $character === '[' || $character === '{') { $depth++; continue; }
            if ($character === ')' || $character === ']' || $character === '}') { $depth--; continue; }

            if ($character === ',' && $depth === 0) {
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
            $character = $expression[$i];

            if ($inSingle) {
                if ($character === '\\' && $i + 1 < $len) { $i++; continue; }
                if ($character === "'") $inSingle = false;
                continue;
            }
            if ($inDouble) {
                if ($character === '\\' && $i + 1 < $len) { $i++; continue; }
                if ($character === '"') $inDouble = false;
                continue;
            }

            if ($character === "'") { $inSingle = true; continue; }
            if ($character === '"') { $inDouble = true; continue; }
            if ($character === '(' || $character === '[' || $character === '{') { $depth++; continue; }
            if ($character === ')' || $character === ']' || $character === '}') { $depth--; continue; }

            if ($character === ',' && $depth === 0) {
                return trim(substr($expression, 0, $i));
            }
        }

        return $expression;
    }
}
