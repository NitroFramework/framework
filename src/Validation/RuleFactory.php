<?php

namespace Nitro\Validation;

use Nitro\Validation\Exceptions\UnknownValidationRuleException;
use Nitro\Validation\Rules\AbstractRule;


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
     * Built-in rules, as the name used in a rule string mapped to the class
     * that implements it.
     *
     * Held as a map rather than a list of register() calls so the set can be
     * read at a glance and the class names need no import apiece.
     *
     * @var array<string, string>
     */
    protected const DEFAULT_RULES = [
        // Presence and absence
        'required'             => 'Required',
        'required_if'          => 'RequiredIf',
        'required_if_accepted' => 'RequiredIfAccepted',
        'required_if_declined' => 'RequiredIfDeclined',
        'required_unless'      => 'RequiredUnless',
        'required_with'        => 'RequiredWith',
        'required_with_all'    => 'RequiredWithAll',
        'required_without'     => 'RequiredWithout',
        'required_without_all' => 'RequiredWithoutAll',
        'required_array_keys'  => 'RequiredArrayKeys',
        'filled'               => 'Filled',
        'present'              => 'Present',
        'present_if'           => 'PresentIf',
        'present_unless'       => 'PresentUnless',
        'present_with'         => 'PresentWith',
        'present_with_all'     => 'PresentWithAll',
        'missing'              => 'Missing',
        'missing_if'           => 'MissingIf',
        'missing_unless'       => 'MissingUnless',
        'missing_with'         => 'MissingWith',
        'missing_with_all'     => 'MissingWithAll',
        'prohibited'           => 'Prohibited',
        'prohibited_if'        => 'ProhibitedIf',
        'prohibited_unless'    => 'ProhibitedUnless',
        'prohibits'            => 'Prohibits',
        'nullable'             => 'Nullable',

        // Types
        'string'  => 'StringRule',
        'numeric' => 'Numeric',
        'integer' => 'Integer',
        'boolean' => 'BooleanRule',
        'array'   => 'ArrayRule',
        'list'    => 'ListRule',
        'json'    => 'Json',
        'file'    => 'FileRule',
        'image'   => 'ImageRule',

        // Size and comparison
        'size'           => 'Size',
        'max'            => 'Max',
        'min'            => 'Min',
        'between'        => 'Between',
        'gt'             => 'Gt',
        'gte'            => 'Gte',
        'lt'             => 'Lt',
        'lte'            => 'Lte',
        'digits'         => 'Digits',
        'digits_between' => 'DigitsBetween',
        'max_digits'     => 'MaxDigits',
        'min_digits'     => 'MinDigits',
        'multiple_of'    => 'MultipleOf',
        'decimal'        => 'Decimal',

        // String format
        'alpha'       => 'Alpha',
        'alpha_dash'  => 'AlphaDash',
        'alpha_num'   => 'AlphaNum',
        'ascii'       => 'Ascii',
        'lowercase'   => 'Lowercase',
        'uppercase'   => 'Uppercase',
        'email'       => 'Email',
        'url'         => 'Url',
        'active_url'  => 'ActiveUrl',
        'ip'          => 'Ip',
        'ipv4'        => 'Ipv4',
        'ipv6'        => 'Ipv6',
        'mac_address' => 'MacAddress',
        'uuid'        => 'Uuid',
        'ulid'        => 'Ulid',
        'hex_color'   => 'HexColor',
        'timezone'    => 'Timezone',

        // String content
        'regex'              => 'Regex',
        'not_regex'          => 'NotRegex',
        'starts_with'        => 'StartsWith',
        'ends_with'          => 'EndsWith',
        'doesnt_start_with'  => 'DoesntStartWith',
        'doesnt_end_with'    => 'DoesntEndWith',
        'contains'           => 'Contains',
        'doesnt_contain'     => 'DoesntContain',

        // Sets and other fields
        'in'       => 'In',
        'not_in'   => 'NotIn',
        'in_array' => 'InArray',
        'distinct' => 'Distinct',
        'same'     => 'Same',
        'different' => 'Different',
        'confirmed' => 'Confirmed',

        // Acceptance
        'accepted'    => 'Accepted',
        'accepted_if' => 'AcceptedIf',
        'declined'    => 'Declined',
        'declined_if' => 'DeclinedIf',

        // Dates
        'date'             => 'Date',
        'date_format'      => 'DateFormat',
        'date_equals'      => 'DateEquals',
        'after'            => 'After',
        'after_or_equal'   => 'AfterOrEqual',
        'before'           => 'Before',
        'before_or_equal'  => 'BeforeOrEqual',

        // Uploads
        'mimes'      => 'Mimes',
        'mimetypes'  => 'Mimetypes',
        'extensions' => 'Extensions',
        'dimensions' => 'Dimensions',

        // Database
        'unique' => 'Unique',
        'exists' => 'Exists',
    ];

    /**
     * Register all default built-in rules
     */
    protected function registerDefaultRules(): void
    {
        foreach (self::DEFAULT_RULES as $name => $class) {
            $this->register($name, __NAMESPACE__ . '\\Rules\\' . $class);
        }
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