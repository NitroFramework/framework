<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: @extends/@section/@yield layout directives.
 */
trait CompilesLayouts
{
    protected string $lastSection = '';

    protected function compileExtends(string $args): string
    {
        $expression = $this->stripParentheses($args);
        return "<?php \$this->setParentView({$expression}); ?>";
    }

    protected function compileSection(string $args): string
    {
        $this->lastSection = trim($this->stripParentheses($args), "'\" ");
        return "<?php \$this->startSection{$args}; ?>";
    }

    protected function compileEndsection(string $args): string
    {
        return "<?php \$this->endSection(); ?>";
    }

    protected function compileStop(string $args): string
    {
        return "<?php \$this->endSection(); ?>";
    }

    protected function compileShow(string $args): string
    {
        return "<?php echo \$this->yieldSection(); ?>";
    }

    protected function compileParent(string $args): string
    {
        $escapedLastSection = strtr($this->lastSection, ['\\' => '\\\\', "'" => "\\'"]);
        return "<?php echo \$this->getParentContent('{$escapedLastSection}'); ?>";
    }

    protected function compileYield(string $args): string
    {
        return "<?php echo \$this->getSection{$args}; ?>";
    }

    protected function compileAppend(string $args): string
    {
        return "<?php \$this->appendSection(); ?>";
    }

    protected function compileOverwrite(string $args): string
    {
        return "<?php \$this->endSection(true); ?>";
    }

    protected function stripParentheses(string $expression): string
    {
        if (str_starts_with($expression, '(')) {
            $expression = substr($expression, 1, -1);
        }
        return $expression;
    }
}
