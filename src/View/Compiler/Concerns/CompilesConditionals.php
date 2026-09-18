<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Compiles the directives that decide whether markup is emitted.
 *
 * Uses PHP's alternative syntax, since a closing brace separated from its
 * opening one by markup is unreadable.
 */
trait CompilesConditionals
{
    /**
     * Whether the next `@case` is the first in its `@switch`.
     *
     * PHP allows no output between `switch` and its first `case`.
     */
    protected bool $firstCaseInSwitch = true;

    // ─── if / elseif / else ───────────────────────────────

    /**
     * `@if($condition)` — emit what follows only when the condition holds.
     */
    protected function compileIf(string $args): string
    {
        return "<?php if{$args}: ?>";
    }

    /**
     * `@elseif($condition)` — a further condition when the previous failed.
     */
    protected function compileElseif(string $args): string
    {
        return "<?php elseif{$args}: ?>";
    }

    /**
     * `@else` — what to emit when every condition above failed.
     */
    protected function compileElse(string $args): string
    {
        return '<?php else: ?>';
    }

    /**
     * `@endif` — closes `@if`.
     */
    protected function compileEndif(string $args): string
    {
        return '<?php endif; ?>';
    }

    // ─── unless ───────────────────────────────────────────

    /**
     * `@unless($condition)` — the inverse of `@if`, for conditions that read
     * better stated positively and acted on negatively.
     */
    protected function compileUnless(string $args): string
    {
        return "<?php if(!{$args}): ?>";
    }

    /**
     * `@endunless` — closes `@unless`.
     */
    protected function compileEndunless(string $args): string
    {
        return '<?php endif; ?>';
    }

    // ─── isset / empty ────────────────────────────────────

    /**
     * `@isset($value)` — emit what follows only when the variable is set and
     * not null, without the notice a bare `@if` on an undefined variable raises.
     */
    protected function compileIsset(string $args): string
    {
        return "<?php if(isset{$args}): ?>";
    }

    /**
     * `@endisset` — closes `@isset`.
     */
    protected function compileEndisset(string $args): string
    {
        return '<?php endif; ?>';
    }

    /**
     * `@endempty` — closes `@empty`.
     */
    protected function compileEndempty(string $args): string
    {
        return '<?php endif; ?>';
    }

    // ─── switch / case ────────────────────────────────────

    /**
     * `@switch($value)` — open a branch on one value.
     *
     * The PHP block is left open deliberately; {@see compileCase()} closes it,
     * because PHP permits no output between `switch` and its first `case`.
     */
    protected function compileSwitch(string $args): string
    {
        $this->firstCaseInSwitch = true;

        return "<?php switch{$args}:";
    }

    /**
     * `@case($value)` — one branch of a `@switch`.
     *
     * The first case closes the block `@switch` opened; later ones open and
     * close their own.
     */
    protected function compileCase(string $args): string
    {
        if ($this->firstCaseInSwitch) {
            $this->firstCaseInSwitch = false;

            return "case {$args}: ?>";
        }

        return "<?php case {$args}: ?>";
    }

    /**
     * `@default` — the branch taken when no case matched.
     */
    protected function compileDefault(string $args): string
    {
        return '<?php default: ?>';
    }

    /**
     * `@endswitch` — closes `@switch`.
     */
    protected function compileEndswitch(string $args): string
    {
        return '<?php endswitch; ?>';
    }

    // ─── auth / guest ─────────────────────────────────────

    /**
     * `@auth` — emit what follows only for a signed-in visitor.
     */
    protected function compileAuth(string $args): string
    {
        return '<?php if(auth()->check()): ?>';
    }

    /**
     * `@endauth` — closes `@auth`.
     */
    protected function compileEndauth(string $args): string
    {
        return '<?php endif; ?>';
    }

    /**
     * `@guest` — emit what follows only for a visitor who is not signed in.
     */
    protected function compileGuest(string $args): string
    {
        return '<?php if(auth()->guest()): ?>';
    }

    /**
     * `@endguest` — closes `@guest`.
     */
    protected function compileEndguest(string $args): string
    {
        return '<?php endif; ?>';
    }

    // ─── environment ──────────────────────────────────────

    /**
     * `@env('local')` or `@env(['local', 'staging'])` — emit what follows only
     * in the named environments.
     */
    protected function compileEnv(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php if(in_array(\$this->getEnvironment(), (array) {$expression})): ?>";
    }

    /**
     * `@endenv` — closes `@env`.
     */
    protected function compileEndenv(string $args): string
    {
        return '<?php endif; ?>';
    }

    /**
     * `@production` — the common case of `@env('production')`.
     */
    protected function compileProduction(string $args): string
    {
        return "<?php if(\$this->getEnvironment() === 'production'): ?>";
    }

    /**
     * `@endproduction` — closes `@production`.
     */
    protected function compileEndproduction(string $args): string
    {
        return '<?php endif; ?>';
    }

    // ─── section checks ───────────────────────────────────

    /**
     * `@hasSection('name')` — emit what follows only when a child defined that
     * section with something other than whitespace.
     */
    protected function compileHasSection(string $args): string
    {
        return "<?php if(!empty(trim(\$this->getSection{$args}))): ?>";
    }

    /**
     * `@sectionMissing('name')` — the inverse of `@hasSection`, for the
     * fallback a layout renders when a child supplied nothing.
     */
    protected function compileSectionMissing(string $args): string
    {
        return "<?php if(empty(trim(\$this->getSection{$args}))): ?>";
    }
}
