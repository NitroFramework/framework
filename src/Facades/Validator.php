<?php

namespace Nitro\Facades;

use Nitro\Validation\Validator as BaseValidator;

/**
 * Validator facade — Laravel's `Validator::make()`.
 *
 *   $validator = Validator::make($data, $rules, $messages);
 *   if ($validator->fails()) { ... $validator->errors() ... }
 */
class Validator
{
    public static function make(array $data, array $rules, array $messages = []): BaseValidator
    {
        return new BaseValidator($data, $rules, $messages);
    }
}
