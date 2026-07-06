<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: @if/@elseif/@else/@unless/@isset conditionals.
 */
trait CompilesConditionals
{
    protected bool $firstCaseInSwitch = true;

    // ── if / elseif / else ───────────────────────────────

    protected function compileIf(string $args): string
    {
        return "<?php if{$args}: ?>";
    }

    protected function compileElseif(string $args): string
    {
        return "<?php elseif{$args}: ?>";
    }

    protected function compileElse(string $args): string
    {
        return "<?php else: ?>";
    }

    protected function compileEndif(string $args): string
    {
        return "<?php endif; ?>";
    }

    // ── unless ───────────────────────────────────────────

    protected function compileUnless(string $args): string
    {
        return "<?php if(!{$args}): ?>";
    }

    protected function compileEndunless(string $args): string
    {
        return "<?php endif; ?>";
    }

    // ── isset / empty ────────────────────────────────────

    protected function compileIsset(string $args): string
    {
        return "<?php if(isset{$args}): ?>";
    }

    protected function compileEndisset(string $args): string
    {
        return "<?php endif; ?>";
    }

    protected function compileEndempty(string $args): string
    {
        return "<?php endif; ?>";
    }

    // ── switch / case ────────────────────────────────────

    protected function compileSwitch(string $args): string
    {
        $this->firstCaseInSwitch = true;

        return "<?php switch{$args}:";
    }

    protected function compileCase(string $args): string
    {
        if ($this->firstCaseInSwitch) {
            $this->firstCaseInSwitch = false;

            return "case {$args}: ?>";
        }

        return "<?php case {$args}: ?>";
    }

    protected function compileDefault(string $args): string
    {
        return '<?php default: ?>';
    }

    protected function compileEndswitch(string $args): string
    {
        return '<?php endswitch; ?>';
    }

    // ── auth / guest (session-based) ─────────────────────

    protected function compileAuth(string $args): string
    {
        return '<?php if(auth()->check()): ?>';
    }

    protected function compileEndauth(string $args): string
    {
        return "<?php endif; ?>";
    }

    protected function compileGuest(string $args): string
    {
        return '<?php if(auth()->guest()): ?>';
    }

    protected function compileEndguest(string $args): string
    {
        return "<?php endif; ?>";
    }

    // ── env / production ─────────────────────────────────

    protected function compileEnv(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php if(in_array(\$this->getEnvironment(), (array) {$expression})): ?>";
    }

    protected function compileEndenv(string $args): string
    {
        return "<?php endif; ?>";
    }

    protected function compileProduction(string $args): string
    {
        return "<?php if(\$this->getEnvironment() === 'production'): ?>";
    }

    protected function compileEndproduction(string $args): string
    {
        return "<?php endif; ?>";
    }

    // ── section checks ───────────────────────────────────

    protected function compileHasSection(string $args): string
    {
        return "<?php if(!empty(trim(\$this->getSection{$args}))): ?>";
    }

    protected function compileSectionMissing(string $args): string
    {
        return "<?php if(empty(trim(\$this->getSection{$args}))): ?>";
    }
}
