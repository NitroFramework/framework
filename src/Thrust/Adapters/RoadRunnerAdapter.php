<?php

namespace Nitro\Thrust\Adapters;

/**
 * Worker adapter for the RoadRunner runtime.
 */
class RoadRunnerAdapter
{
    public function isAvailable(): bool
    {
        return class_exists('Spiral\RoadRunner\Worker');
    }
}
