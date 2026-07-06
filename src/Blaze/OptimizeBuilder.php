<?php

namespace Nitro\Blaze;

/**
 * Fluent helper returned by Blaze::optimize() for registering one or more
 * directories of components to compile.
 */
class OptimizeBuilder
{
    public function __construct(protected BlazeManager $manager) {}

    /** Optimize the plain-template components under a directory. */
    public function in(string $directory): static
    {
        $this->manager->optimizeDirectory($directory);

        return $this;
    }
}
