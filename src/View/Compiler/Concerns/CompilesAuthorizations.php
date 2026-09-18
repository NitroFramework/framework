<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: `@can` / `@cannot` / `@canany` authorization checks.
 *
 * Each compiles to a Gate call against the authenticated user, so a template
 * asks the same question a controller would rather than re-deriving it from
 * whatever attributes happen to be in scope.
 */
trait CompilesAuthorizations
{
    /** Compile the `@can` directive. */
    protected function compileCan(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php if (app('gate')->check({$expression})): ?>";
    }

    /** Compile the `@elsecan` directive. */
    protected function compileElsecan(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php elseif (app('gate')->check({$expression})): ?>";
    }

    /** Compile the `@endcan` directive. */
    protected function compileEndcan(string $args): string
    {
        return '<?php endif; ?>';
    }

    /** Compile the `@cannot` directive. */
    protected function compileCannot(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php if (app('gate')->denies({$expression})): ?>";
    }

    /** Compile the `@elsecannot` directive. */
    protected function compileElsecannot(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php elseif (app('gate')->denies({$expression})): ?>";
    }

    /** Compile the `@endcannot` directive. */
    protected function compileEndcannot(string $args): string
    {
        return '<?php endif; ?>';
    }

    /** Compile the `@canany` directive, true when any of the abilities pass. */
    protected function compileCanany(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php if (app('gate')->any({$expression})): ?>";
    }

    /** Compile the `@elsecanany` directive. */
    protected function compileElsecanany(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php elseif (app('gate')->any({$expression})): ?>";
    }

    /** Compile the `@endcanany` directive. */
    protected function compileEndcanany(string $args): string
    {
        return '<?php endif; ?>';
    }
}
