<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: {{-- --}} comments.
 */
trait CompilesComments
{
    protected function compileComments(string $content): string
    {
        $content = preg_replace('/\{\{--.*?--\}\}/su', '', $content);

        return preg_replace('/\B@comment.*?\B@endcomment/su', '', $content);
    }
}
