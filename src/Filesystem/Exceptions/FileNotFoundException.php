<?php

namespace Nitro\Filesystem\Exceptions;

use RuntimeException;

/**
 * A file was read that is not there.
 *
 * Reading is the one operation with no useful falsy answer: an empty string is
 * a legitimate file, and null forces every caller to check. So a read of a
 * path that does not exist throws, and names the path.
 */
class FileNotFoundException extends RuntimeException
{
    public static function at(string $path): self
    {
        return new self("File does not exist at path {$path}.");
    }
}
