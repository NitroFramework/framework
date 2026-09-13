<?php

namespace Nitro\Validation;

use Nitro\Validation\Exceptions\UnknownValidationRuleException;
use Nitro\Validation\Rules\AbstractRule;
use Nitro\Validation\Rules\Required;
use Nitro\Validation\Rules\Nullable;
use Nitro\Validation\Rules\StringRule;
use Nitro\Validation\Rules\Numeric;
use Nitro\Validation\Rules\Integer;
use Nitro\Validation\Rules\Email;
use Nitro\Validation\Rules\Date;
use Nitro\Validation\Rules\Max;
use Nitro\Validation\Rules\Min;
use Nitro\Validation\Rules\In;
use Nitro\Validation\Rules\Regex;
use Nitro\Validation\Rules\Url;
use Nitro\Validation\Rules\Confirmed;
use Nitro\Validation\Rules\Unique;
use Nitro\Validation\Rules\Exists;
use Nitro\Validation\Rules\ArrayRule;
use Nitro\Validation\Rules\BooleanRule;
use Nitro\Validation\Rules\Between;
use Nitro\Validation\Rules\Same;
use Nitro\Validation\Rules\Different;
use Nitro\Validation\Rules\RequiredIf;
use Nitro\Validation\Rules\RequiredWith;
use Nitro\Validation\Rules\After;
use Nitro\Validation\Rules\Before;


/**
 * RuleFactory
 * 
 * Creates validation rule instances
 * Manages rule registration and instantiation
 */
class RuleFactory
{
    /**
     * @var array<string, string> Rule name => Rule class name
     */
    protected array $rules = [];

    public function __construct()
    {
        $this->registerDefaultRules();
    }

    /**
     * Register all default built-in rules
     */
    protected function registerDefaultRules(): void
    {
        $this->register('required', Required::class);
        $this->register('nullable', Nullable::class);
        $this->register('string', StringRule::class);
        $this->register('numeric', Numeric::class);
        $this->register('integer', Integer::class);
        $this->register('email', Email::class);
        $this->register('date', Date::class);
        $this->register('max', Max::class);
        $this->register('min', Min::class);
        $this->register('in', In::class);
        $this->register('regex', Regex::class);
        $this->register('url', Url::class);
        $this->register('confirmed', Confirmed::class);
        $this->register('unique', Unique::class);
        $this->register('exists', Exists::class);
        $this->register('array', ArrayRule::class);
        $this->register('boolean', BooleanRule::class);
        $this->register('between', Between::class);
        $this->register('same', Same::class);
        $this->register('different', Different::class);
        $this->register('required_if', RequiredIf::class);
        $this->register('required_with', RequiredWith::class);
        $this->register('after', After::class);
        $this->register('before', Before::class);
    }

    /**
     * Register a rule
     */
    public function register(string $name, string $class): void
    {
        if (!is_subclass_of($class, AbstractRule::class)) {
            throw new \InvalidArgumentException(
                "Rule class '{$class}' must extend " . AbstractRule::class
            );
        }

        $this->rules[$name] = $class;
    }

    /**
     * Create a rule instance
     *
     * @param string $ruleName Rule name (e.g., 'email', 'max', 'unique:users,email')
     * @return AbstractRule Rule instance
     * @throws UnknownValidationRuleException If the base rule name isn't registered
     */
    public function create(string $ruleName): AbstractRule
    {
        // Extract base rule name (before colon)
        $baseName = explode(':', $ruleName)[0];

        if (!isset($this->rules[$baseName])) {
            throw new UnknownValidationRuleException(
                "Validation rule '{$baseName}' is not registered. "
                . "Check for typos or register it via RuleFactory::register()."
            );
        }

        $className = $this->rules[$baseName];
        $rule = new $className();

        // Parse parameters from rule string
        $rule->parseParameters($ruleName);

        return $rule;
    }

    /**
     * Check if a rule is registered
     */
    public function has(string $name): bool
    {
        return isset($this->rules[$name]);
    }

    /**
     * Get all registered rule names
     */
    public function all(): array
    {
        return array_keys($this->rules);
    }
}