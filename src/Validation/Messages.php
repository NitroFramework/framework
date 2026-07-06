<?php

namespace Nitro\Validation;

/**
 * Messages
 * 
 * Manages validation error messages
 * Allows customization of default messages and per-rule messages
 */
class Messages
{
    /**
     * @var array<string, string> Custom message overrides
     */
    protected array $messages;

    public function __construct(array $customMessages = [])
    {
        $this->messages = array_merge(
            $this->defaults(),
            $customMessages
        );
    }

    /**
     * Get default validation messages
     */
    protected function defaults(): array
    {
        return [
            'required' => 'The {attribute} field is required.',
            'string' => 'The {attribute} must be a string.',
            'numeric' => 'The {attribute} must be numeric.',
            'integer' => 'The {attribute} must be an integer.',
            'email' => 'The {attribute} must be a valid email address.',
            'date' => 'The {attribute} must be a valid date (YYYY-MM-DD).',
            'max' => 'The {attribute} may not exceed {max} characters.',
            'max.numeric' => 'The {attribute} may not be greater than {max}.',
            'min' => 'The {attribute} must be at least {min} characters.',
            'min.numeric' => 'The {attribute} must be at least {min}.',
            'in' => 'The {attribute} must be one of: {values}.',
            'regex' => 'The {attribute} format is invalid.',
            'url' => 'The {attribute} must be a valid URL.',
            'confirmed' => 'The {attribute} confirmation does not match.',
            'unique' => 'The {attribute} has already been taken.',
        ];
    }

    /**
     * Get a message by key
     * 
     * Supports hierarchical keys:
     *   'email' -> general email message
     *   'email.unique' -> specific unique error for email field
     *   'max' -> general max message
     *   'max.numeric' -> numeric-specific max message
     */
    public function get(string $key, string $default = ''): string
    {
        return $this->messages[$key] ?? $default;
    }

    /**
     * Set a custom message
     */
    public function set(string $key, string $message): void
    {
        $this->messages[$key] = $message;
    }

    /**
     * Set multiple custom messages at once
     */
    public function setMultiple(array $messages): void
    {
        $this->messages = array_merge($this->messages, $messages);
    }

    /**
     * Get all messages
     */
    public function all(): array
    {
        return $this->messages;
    }
}