<?php

namespace Nitro\Livewire\Exceptions;

use RuntimeException;

/**
 * Thrown when a client update tries to change a #[Locked] component property.
 */
class CannotUpdateLockedProperty extends RuntimeException
{
    public function __construct(string $property)
    {
        parent::__construct(
            "Cannot update locked property [{$property}]: it is marked #[Locked] and "
            . 'may not be changed from the browser.'
        );
    }
}
