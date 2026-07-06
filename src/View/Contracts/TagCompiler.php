<?php

namespace Nitro\View\Contracts;

/**
 * Compiles component tags (<x-...>) in template source.
 */
interface TagCompiler
{
    public function compile(string $value): string;
}