<?php

namespace Nitro\View\Contracts;

use Nitro\Container\Container;
use Nitro\View\Engine\View;

/**
 * Registers and fires view composers.
 */
interface ViewComposerResolver
{
    public function register(string|array $templates, callable|string $composer): void;
    public function fire(View $view, Container $container): void;
}