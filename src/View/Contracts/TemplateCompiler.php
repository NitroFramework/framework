<?php

namespace Nitro\View\Contracts;

/**
 * Compiles Blade template source into executable PHP.
 */
interface TemplateCompiler
{
    public function compile(string $content): string;
}