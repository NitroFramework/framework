<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: `@lang` and `@choice` translation directives.
 */
trait CompilesTranslations
{
    /** Compile the `@lang` directive. */
    protected function compileLang(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo \\nitro_e(__({$expression})); ?>";
    }

    /** Compile the `@choice` directive, which picks a plural form by count. */
    protected function compileChoice(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo \\nitro_e(trans_choice({$expression})); ?>";
    }
}
