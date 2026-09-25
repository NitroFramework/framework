<?php

use Nitro\Container\Container;
use Nitro\Validation\Factory as ValidationFactory;
use Nitro\Validation\Validator;

if (! function_exists('validator')) {
    /**
     * The validator factory, or a validator for this data.
     *
     *   validator($data, ['email' => 'required|email'])->validated();
     *   validator()->extend('uppercase', fn ($attribute, $value) => ...);
     *
     * Goes through the application's factory when there is one, so rules added
     * with Validator::extend() apply; a fresh factory otherwise, as in a test
     * with no application.
     *
     * @param array<string, mixed>|null $data
     * @param array<string, mixed>      $rules
     * @param array<string, string>     $messages
     * @param array<string, string>     $attributes
     */
    function validator(?array $data = null, array $rules = [], array $messages = [], array $attributes = []): ValidationFactory|Validator
    {
        $factory = Container::hasInstance() && Container::getInstance()->has(ValidationFactory::class)
            ? Container::getInstance()->resolve(ValidationFactory::class)
            : new ValidationFactory();

        if (func_num_args() === 0) {
            return $factory;
        }

        return $factory->make($data ?? [], $rules, $messages, $attributes);
    }
}

if (!function_exists('validate_email')) {
    /**
     * Validate email address
     * 
     * @param string $email
     * @return bool
     */
    function validate_email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('validate_url')) {
    /**
     * Validate URL
     * 
     * @param string $url
     * @return bool
     */
    function validate_url(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}

if (!function_exists('validate_ip')) {
    /**
     * Validate IP address
     * 
     * @param string $ip
     * @return bool
     */
    function validate_ip(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}

if (!function_exists('is_json')) {
    /**
     * Check if string is valid JSON
     * 
     * @param string $string
     * @return bool
     */
    function is_json(string $string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}