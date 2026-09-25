<?php

namespace Nitro\Facades;

use Nitro\Validation\Validator as BaseValidator;
use Nitro\Validation\Factory;
use Closure;

/**
 * Validator facade — makes validators, and adds rules to all of them.
 *
 *   $validator = Validator::make($data, $rules, $messages);
 *   if ($validator->fails()) { ... $validator->errors() ... }
 *
 *   Validator::extend('uppercase', fn ($attribute, $value) => strtoupper($value) === $value);
 *
 * @method static BaseValidator make(array $data, array $rules, array $messages = [], array $attributes = [])
 * @method static array validate(array $data, array $rules, array $messages = [], array $attributes = [])
 * @method static void extend(string $rule, Closure|string $extension, ?string $message = null)
 * @method static void extendImplicit(string $rule, Closure|string $extension, ?string $message = null)
 * @method static void extendDependent(string $rule, Closure|string $extension, ?string $message = null)
 * @method static array getDependentExtensions()
 * @method static void replacer(string $rule, Closure|string $replacer)
 * @method static void resolver(Closure $resolver)
 * @method static array getExtensions()
 * @method static array getImplicitExtensions()
 * @method static array getReplacers()
 *
 * @see Factory
 */
class Validator extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'validator';
    }
}
