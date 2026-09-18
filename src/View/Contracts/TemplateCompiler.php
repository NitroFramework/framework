<?php

namespace Nitro\View\Contracts;

/**
 * Compiles Blade source into executable PHP.
 */
interface TemplateCompiler
{
    /**
     * Compile the given template source.
     *
     * @param  string $content
     * @return string
     */
    public function compile(string $content): string;
}
