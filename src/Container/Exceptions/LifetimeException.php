<?php

namespace Nitro\Container\Exceptions;

use Nitro\Container\Lifetime;
use RuntimeException;

/**
 * A service was about to be given a dependency that does not live as long as
 * it does.
 *
 * Thrown while building, so the resolution chain in the message is the one
 * that would have captured the shorter-lived object.
 */
class LifetimeException extends RuntimeException
{
    /**
     * @param array<int, string> $chain The resolution path, outermost first.
     */
    public static function captured(
        string $dependency,
        Lifetime $dependencyLifetime,
        string $consumer,
        Lifetime $consumerLifetime,
        array $chain,
    ): self {
        $path = implode(' → ', $chain);

        return new self(
            "[{$consumer}] lives for the {$consumerLifetime->name} and cannot depend on "
            . "[{$dependency}], which lives for the {$dependencyLifetime->name}: the first "
            . "instance would be held for every request after it.\n"
            . "  Resolved through: {$path}\n"
            . "  Either register [{$consumer}] with scoped(), or have it resolve "
            . "[{$dependency}] from the container per call instead of taking it in the "
            . 'constructor.'
        );
    }

}
