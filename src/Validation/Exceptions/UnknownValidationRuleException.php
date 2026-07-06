<?php

namespace Nitro\Validation\Exceptions;

use RuntimeException;

/**
 * Thrown when the validator encounters a rule name that isn't registered
 * — typically a typo in a rule string like `'emial'`. Previously the
 * validator caught this and silently skipped the rule, which made the
 * field appear to pass when it was never actually validated.
 */
class UnknownValidationRuleException extends RuntimeException
{
}
