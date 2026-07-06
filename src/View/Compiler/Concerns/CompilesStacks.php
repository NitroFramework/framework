<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: @push/@stack stacks.
 */
trait CompilesStacks
{
    protected function compileStack(string $args): string
    {
        return "<?php echo \$this->yieldStack{$args}; ?>";
    }

    protected function compilePush(string $args): string
    {
        return "<?php \$this->startPush{$args}; ?>";
    }

    protected function compileEndpush(string $args): string
    {
        return "<?php \$this->endPush(); ?>";
    }

    protected function compilePushOnce(string $args): string
    {
        $parts = explode(',', $this->stripParentheses($args), 2);
        $stack = trim($parts[0]);
        $id = !empty(trim($parts[1] ?? ''))
            ? trim($parts[1])
            : "'" . bin2hex(random_bytes(16)) . "'";

        return "<?php if(!\$this->hasRenderedOnce({$id})): \$this->markRenderedOnce({$id}); " .
            "\$this->startPush({$stack}); ?>";
    }

    protected function compileEndpushOnce(string $args): string
    {
        return "<?php \$this->endPush(); endif; ?>";
    }

    protected function compilePrepend(string $args): string
    {
        return "<?php \$this->startPrepend{$args}; ?>";
    }

    protected function compileEndprepend(string $args): string
    {
        return "<?php \$this->endPrepend(); ?>";
    }

    protected function compilePrependOnce(string $args): string
    {
        $parts = explode(',', $this->stripParentheses($args), 2);
        $stack = trim($parts[0]);
        $id = !empty(trim($parts[1] ?? ''))
            ? trim($parts[1])
            : "'" . bin2hex(random_bytes(16)) . "'";

        return "<?php if(!\$this->hasRenderedOnce({$id})): \$this->markRenderedOnce({$id}); " .
            "\$this->startPrepend({$stack}); ?>";
    }

    protected function compileEndprependOnce(string $args): string
    {
        return "<?php \$this->endPrepend(); endif; ?>";
    }
}
