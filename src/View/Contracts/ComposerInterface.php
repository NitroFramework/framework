<?php

namespace Nitro\View\Contracts;
use Nitro\View\Engine\View;

/**
 * Contract for a view composer — binds data to a view before it renders.
 */
interface ComposerInterface
{
    public function compose(View $view): void;
}
