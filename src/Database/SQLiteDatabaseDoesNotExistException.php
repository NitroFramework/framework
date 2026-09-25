<?php

namespace Nitro\Database;

use InvalidArgumentException;

/**
 * The SQLite file a connection names is not there.
 *
 * Raised instead of letting PDO create an empty database at a mistyped path,
 * which would look like a working connection with every table missing.
 */
class SQLiteDatabaseDoesNotExistException extends InvalidArgumentException
{
    public function __construct(public string $path)
    {
        parent::__construct("Database file at path [{$path}] does not exist. Ensure this is an absolute path to the database.");
    }
}
