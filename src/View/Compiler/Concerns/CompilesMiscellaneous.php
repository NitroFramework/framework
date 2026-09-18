<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: assorted directives not covered by the other concerns.
 */
trait CompilesMiscellaneous
{
    /** Compile the `@once` directive. */
    protected function compileOnce(string $args): string
    {
        $id = !empty($args)
            ? $this->stripParentheses($args)
            : "'" . bin2hex(random_bytes(16)) . "'";

        return "<?php if(!\$this->hasRenderedOnce({$id})): \$this->markRenderedOnce({$id}); ?>";
    }

    /** Compile the `@endonce` directive. */
    protected function compileEndonce(string $args): string
    {
        return "<?php endif; ?>";
    }

    /** Compile the `@error` directive. */
    protected function compileError(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php if(isset(\$errors[{$expression}]) && !empty(\$errors[{$expression}])): ?>";
    }

    /** Compile the `@enderror` directive. */
    protected function compileEnderror(string $args): string
    {
        return "<?php endif; ?>";
    }

    /** Compile the `@session` directive, true when the key is present. */
    protected function compileSession(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php if (session()->has({$expression})): \$value = session()->get({$expression}); ?>";
    }

    /** Compile the `@endsession` directive. */
    protected function compileEndsession(string $args): string
    {
        return '<?php unset($value); endif; ?>';
    }

    /** Compile the `@js` directive, which renders a value as a JavaScript literal. */
    protected function compileJs(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php echo \\Nitro\\Support\\Js::from({$expression}); ?>";
    }

    /** Compile the `@use` directive, which imports a class into the template. */
    protected function compileUse(string $args): string
    {
        $expression = trim($this->stripParentheses($args), '\'"');

        return "<?php use {$expression}; ?>";
    }

    /** Compile the `@unset` directive. */
    protected function compileUnset(string $args): string
    {
        $expression = $this->stripParentheses($args);

        return "<?php unset({$expression}); ?>";
    }
}
