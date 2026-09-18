<?php

namespace Nitro\View\Contracts;

/**
 * Rewrites component tags in template source into directives.
 */
interface TagCompiler
{
    /**
     * Compile the component tags within the given string.
     *
     * @param  string $value
     * @return string
     */
    public function compile(string $value): string;
}
