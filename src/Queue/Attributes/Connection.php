<?php

namespace Nitro\Queue\Attributes;

use Attribute;
use UnitEnum;

/** The queue connection this job is pushed to. */
#[Attribute(Attribute::TARGET_CLASS)]
class Connection
{
    public string $connection;

    public function __construct(UnitEnum|string $connection)
    {
        $this->connection = $connection instanceof UnitEnum
            ? (string) ($connection->value ?? $connection->name)
            : $connection;
    }
}
