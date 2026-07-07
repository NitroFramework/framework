<?php

namespace Nitro\Encryption;

use RuntimeException;

/** Thrown when a payload cannot be decrypted or its MAC/tag is invalid. */
class DecryptException extends RuntimeException
{
}
