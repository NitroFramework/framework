<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: `@push` / `@prepend` / `@stack` stacks.
 */
trait CompilesStacks
{
    /** Compile the `@stack` directive. */
    protected function compileStack(string $args): string
    {
        return "<?php echo \$this->yieldStack{$args}; ?>";
    }

    /** Compile the `@push` directive. */
    protected function compilePush(string $args): string
    {
        return "<?php \$this->startPush{$args}; ?>";
    }

    /** Compile the `@endpush` directive. */
    protected function compileEndpush(string $args): string
    {
        return "<?php \$this->endPush(); ?>";
    }

    /**
     * Compile the `@pushOnce` directive.
     *
     * An id may be given to share the guard across templates; without one, a
     * random id makes the block unique to this call site.
     */
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

    /** Compile the `@endPushOnce` directive. */
    protected function compileEndpushOnce(string $args): string
    {
        return "<?php \$this->endPush(); endif; ?>";
    }

    /** Compile the `@prepend` directive. */
    protected function compilePrepend(string $args): string
    {
        return "<?php \$this->startPrepend{$args}; ?>";
    }

    /** Compile the `@endprepend` directive. */
    protected function compileEndprepend(string $args): string
    {
        return "<?php \$this->endPrepend(); ?>";
    }

    /**
     * Compile the `@prependOnce` directive.
     *
     * An id may be given to share the guard across templates; without one, a
     * random id makes the block unique to this call site.
     */
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

    /** Compile the `@endPrependOnce` directive. */
    protected function compileEndprependOnce(string $args): string
    {
        return "<?php \$this->endPrepend(); endif; ?>";
    }
}
