<?php

namespace Nitro\Validation;

use Nitro\Validation\Rules\Nullable;

/**
 * Validator
 * 
 * Main validation orchestrator
 * Handles validation logic and error collection
 * 
 * Usage:
 *   $validator = new Validator($data, $rules);
 *   if (!$validator->validate()) {
 *       $errors = $validator->errors();
 *   }
 */
class Validator
{
    /**
     * @var array Data to validate
     */
    protected array $data;

    /**
     * @var array<string, string> Field => rule string
     */
    protected array $rules;

    /**
     * @var ErrorBag Validation errors
     */
    protected ErrorBag $errors;

    /**
     * @var Messages Message manager
     */
    protected Messages $messages;

    /**
     * @var RuleFactory Rule factory
     */
    protected RuleFactory $factory;

    /**
     * @var bool Whether to stop at first error per field
     */
    protected bool $bail = false;

    /** Whether validate() has been run (so passes()/fails() can trigger it). */
    protected bool $hasValidated = false;

    /**
     * Rules that must run even against an empty/absent value (they assert
     * presence). Any other rule is skipped for an optional empty field.
     * Mirrors Laravel's "implicit rules" set.
     */
    private const IMPLICIT_RULES = [
        'required', 'required_if', 'required_unless',
        'required_with', 'required_with_all',
        'required_without', 'required_without_all',
        'present', 'filled', 'accepted', 'accepted_if',
    ];

    /**
     * Create a new validator instance
     */
    public function __construct(
        array $data,
        array $rules,
        array $customMessages = []
    ) {
        $this->data = $data;
        $this->rules = $rules;
        $this->errors = new ErrorBag();
        $this->messages = new Messages($customMessages);
        $this->factory = new RuleFactory();
    }

    /**
     * Validate the data against the rules
     * 
     * @return bool True if validation passes, false if there are errors
     */
    public function validate(): bool
    {
        $this->hasValidated = true;

        foreach ($this->rules as $field => $ruleString) {
            $this->validateField($field, $ruleString);
        }

        return $this->errors->isEmpty();
    }

    /**
     * Validate a single field
     */
    protected function validateField(string $field, string|array $ruleString): void
    {
        // Rules may be a pipe string ('required|email') or an array
        // (['required', 'email', Rule::unique('users')]) whose elements are
        // strings or Stringable Rule expressions. Normalise both to a token list.
        $ruleNames = is_array($ruleString)
            ? array_map(static fn ($rule) => (string) $rule, $ruleString)
            : explode('|', $ruleString);

        // Dot-aware so nested fields ('form.email') validate against nested data.
        $value = \Nitro\Support\Arr::get($this->data, $field);

        // Base rule names (strip 'min:3' → 'min') for presence/implicit checks.
        $baseNames = array_map(
            static fn($r): string => strtolower(trim(explode(':', trim($r), 2)[0])),
            $ruleNames
        );

        // Skip validation of an EMPTY value when the field is optional — either
        // explicitly nullable, or simply not required. Laravel does the same:
        // non-implicit rules (email, min, numeric, …) don't run against an
        // absent/blank optional field, so `email` alone no longer rejects ''.
        // A field carrying an implicit rule (required, …) is still validated so
        // the implicit rule can fail on the empty value.
        $isEmpty = ($value === null || $value === '');
        $hasImplicit = array_intersect($baseNames, self::IMPLICIT_RULES) !== [];

        if ($isEmpty && (in_array('nullable', $baseNames, true) || !$hasImplicit)) {
            return;
        }

        foreach ($ruleNames as $ruleName) {
            $ruleName = trim($ruleName);

            // Skip empty rule names
            if (empty($ruleName) || $ruleName === 'nullable') {
                continue;
            }

            $rule = $this->factory->create($ruleName);
            $rule->setAttribute($field);
            $rule->setValue($value);
            $rule->setData($this->data);

            // Run the validation
            if (!$rule->passes()) {
                $message = $rule->message();
                $this->errors->add($field, $message);

                // Stop at first error for this field if bail is enabled
                if ($this->bail) {
                    break;
                }
            }
        }
    }

    /**
     * Get the error bag
     */
    public function errors(): ErrorBag
    {
        return $this->errors;
    }

    /**
     * Check if validation passed. Runs validation on first call if it hasn't
     * been run yet, so Validator::make($data, $rules)->fails() works directly
     * (Laravel-style) without an explicit validate() call.
     */
    public function passes(): bool
    {
        if (!$this->hasValidated) {
            $this->validate();
        }
        return $this->errors->isEmpty();
    }

    /**
     * Check if validation failed
     */
    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * Enable bail mode - stop at first error
     */
    public function bail(): self
    {
        $this->bail = true;
        return $this;
    }

    /**
     * Register a custom rule
     */
    public function registerRule(string $name, string $class): self
    {
        $this->factory->register($name, $class);
        return $this;
    }

    /**
     * Get the rule factory
     */
    public function getFactory(): RuleFactory
    {
        return $this->factory;
    }

    /**
     * The validated subset of the input (only the fields that have rules).
     * Runs validation first and throws if it fails.
     */
    public function validated(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }

        return array_intersect_key($this->data, $this->rules);
    }

    /**
     * The messages manager.
     */
    public function messages(): Messages
    {
        return $this->messages;
    }

    /**
     * Get validation data
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Get rules
     */
    public function getRules(): array
    {
        return $this->rules;
    }
}