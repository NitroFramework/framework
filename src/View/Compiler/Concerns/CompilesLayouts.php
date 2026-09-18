<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: `@extends` / `@section` / `@yield` layout directives.
 */
trait CompilesLayouts
{
    /** The section most recently opened, so `@parent` knows what it refers to. */
    protected string $lastSection = '';

    /** Compile the `@extends` directive. */
    protected function compileExtends(string $args): string
    {
        $expression = $this->stripParentheses($args);
        return "<?php \$this->setParentView({$expression}); ?>";
    }

    /** Compile the `@section` directive. */
    protected function compileSection(string $args): string
    {
        $this->lastSection = trim($this->stripParentheses($args), "'\" ");
        return "<?php \$this->startSection{$args}; ?>";
    }

    /** Compile the `@endsection` directive. */
    protected function compileEndsection(string $args): string
    {
        return "<?php \$this->endSection(); ?>";
    }

    /** Compile the `@stop` directive. */
    protected function compileStop(string $args): string
    {
        return "<?php \$this->endSection(); ?>";
    }

    /** Compile the `@show` directive. */
    protected function compileShow(string $args): string
    {
        return "<?php echo \$this->yieldSection(); ?>";
    }

    /** Compile the `@parent` directive. */
    protected function compileParent(string $args): string
    {
        $escapedLastSection = strtr($this->lastSection, ['\\' => '\\\\', "'" => "\\'"]);
        return "<?php echo \$this->getParentContent('{$escapedLastSection}'); ?>";
    }

    /** Compile the `@yield` directive. */
    protected function compileYield(string $args): string
    {
        return "<?php echo \$this->getSection{$args}; ?>";
    }

    /** Compile the `@append` directive. */
    protected function compileAppend(string $args): string
    {
        return "<?php \$this->appendSection(); ?>";
    }

    /** Compile the `@overwrite` directive. */
    protected function compileOverwrite(string $args): string
    {
        return "<?php \$this->endSection(true); ?>";
    }

    /** Strip the wrapping parentheses from a directive's arguments. */
    protected function stripParentheses(string $expression): string
    {
        if (str_starts_with($expression, '(')) {
            $expression = substr($expression, 1, -1);
        }
        return $expression;
    }
}
