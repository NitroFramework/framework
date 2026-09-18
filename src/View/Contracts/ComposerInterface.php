<?php

namespace Nitro\View\Contracts;

use Nitro\View\View;

/**
 * Binds data to a view before it renders.
 */
interface ComposerInterface
{
    /**
     * Add data to the given view.
     */
    public function compose(View $view): void;
}
