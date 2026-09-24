<?php

namespace Nitro\Validation;

use Nitro\Support\Arr;
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

    /** Whether to stop the whole run at the first field that fails. */
    protected bool $stopOnFirstFailure = false;

    /**
     * Checks that run once the rules have, with the validator to hand.
     *
     * For what no rule can express — a comparison between two fields, or
     * something only a query can answer. They add errors like any rule.
     *
     * @var array<int, callable(self): void>
     */
    protected array $after = [];

    /**
     * Friendlier names for fields in messages.
     *
     * @var array<string, string>
     */
    protected array $attributeNames = [];

    /**
     * Which rules failed, keyed by field.
     *
     * Kept alongside the messages because a caller branching on the kind of
     * failure should not have to match on the wording of one.
     *
     * @var array<string, array<int, string>>
     */
    protected array $failedRules = [];

    /** Whether validate() has been run (so passes()/fails() can trigger it). */
    protected bool $hasValidated = false;

    /**
     * Rules that must run even against an empty/absent value (they assert
     * presence). Any other rule is skipped for an optional empty field.
     * Mirrors Laravel's "implicit rules" set.
     */
    /**
     * Rule names that steer the validator rather than test a value, and so have
     * no rule class behind them.
     */
    private const FLAG_RULES = ['nullable', 'sometimes', 'bail'];

    /** The conditional forms that drop a field from the validated data. */
    private const EXCLUDE_RULES = [
        'exclude', 'exclude_if', 'exclude_unless', 'exclude_with', 'exclude_without',
    ];

    /** Fields dropped by an exclude rule, as [field => true]. */
    protected array $excluded = [];

    /**
     * Rules that must run even when the value is absent or empty.
     *
     * Everything else is skipped for an empty optional field, so `email` alone
     * does not reject a blank input. These rules exist precisely to have an
     * opinion about emptiness, so skipping them would make them no-ops.
     */
    private const IMPLICIT_RULES = [
        'required', 'required_if', 'required_unless',
        'required_with', 'required_with_all',
        'required_without', 'required_without_all',
        'required_if_accepted', 'required_if_declined',
        'required_array_keys',
        'present', 'present_if', 'present_unless',
        'present_with', 'present_with_all',
        'missing', 'missing_if', 'missing_unless',
        'missing_with', 'missing_with_all',
        'prohibited', 'prohibited_if', 'prohibited_unless', 'prohibits',
        'filled', 'accepted', 'accepted_if', 'declined', 'declined_if',
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

        foreach ($this->expandWildcards($this->rules) as $field => $ruleString) {
            $this->validateField($field, $ruleString);

            if ($this->stopOnFirstFailure && $this->errors->isNotEmpty()) {
                break;
            }
        }

        foreach ($this->after as $callback) {
            $callback($this);
        }

        return $this->errors->isEmpty();
    }

    /**
     * Turn 'items.*.qty' into one rule per item that is actually there.
     *
     * The rest of validation works on concrete dotted paths, and Arr::get
     * already reads those — so a wildcard is resolved once, here, rather than
     * every rule having to know about it. A pattern matching nothing produces
     * no rules, which is why 'items' => 'required|array' is what says the list
     * must be there at all.
     *
     * @param array<string, string|array<int, mixed>> $rules
     * @return array<string, string|array<int, mixed>>
     */
    protected function expandWildcards(array $rules): array
    {
        $expanded = [];

        foreach ($rules as $field => $ruleString) {
            if (! str_contains((string) $field, '*')) {
                $expanded[$field] = $ruleString;

                continue;
            }

            foreach ($this->pathsMatching((string) $field) as $path) {
                $expanded[$path] = $ruleString;
            }
        }

        return $expanded;
    }

    /**
     * Every concrete path in the data that a wildcard pattern reaches.
     *
     * Walked a segment at a time, so 'a.*.b.*.c' expands both levels and only
     * against keys that exist — a rule is never made for an index nobody sent.
     *
     * @return array<int, string>
     */
    protected function pathsMatching(string $pattern): array
    {
        $paths = [''];

        foreach (explode('.', $pattern) as $segment) {
            $next = [];

            foreach ($paths as $path) {
                if ($segment !== '*') {
                    $next[] = $path === '' ? $segment : $path . '.' . $segment;

                    continue;
                }

                $value = $path === '' ? $this->data : Arr::get($this->data, $path);

                if (! is_array($value)) {
                    continue;
                }

                foreach (array_keys($value) as $key) {
                    $next[] = $path === '' ? (string) $key : $path . '.' . $key;
                }
            }

            $paths = $next;
        }

        return $paths;
    }

    /**
     * Validate a single field
     */
    protected function validateField(string $field, string|array $ruleString): void
    {
        // Rules may be a pipe string ('required|email') or an array
        // (['required', 'email', Rule::unique('users')]) whose elements are
        // strings or Stringable Rule expressions. Normalise both to a token list.
        $given = is_array($ruleString) ? $ruleString : explode('|', $ruleString);

        // A rule may arrive as an object or a closure rather than a token.
        // Rule::in() and friends return strings and cast cleanly; a rule class
        // or a closure does not, and casting one used to be how it was lost.
        $ruleNames = [];
        $inlineRules = [];

        foreach ($given as $rule) {
            if (is_object($rule) && ! $rule instanceof \Stringable) {
                $inlineRules[] = $rule;

                continue;
            }

            $ruleNames[] = (string) $rule;
        }

        // Dot-aware so nested fields ('form.email') validate against nested data.
        $value = Arr::get($this->data, $field);

        // Base rule names (strip 'min:3' → 'min') for presence/implicit checks.
        $baseNames = array_map(
            static fn($rule): string => strtolower(trim(explode(':', trim($rule), 2)[0])),
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

        // 'sometimes' validates a field only when it was submitted, so an
        // update that sends a subset of a form does not fail on the fields it
        // left out.
        if (in_array('sometimes', $baseNames, true) && !Arr::has($this->data, $field)) {
            return;
        }

        if ($this->shouldExclude($field, $ruleNames, $baseNames)) {
            $this->excluded[$field] = true;
            return;
        }

        // 'bail' on the field stops it at its first failure, whatever the
        // validator-wide setting is.
        $bail = $this->bail || in_array('bail', $baseNames, true);

        foreach ($ruleNames as $ruleName) {
            $ruleName = trim($ruleName);

            if ($ruleName === '') {
                continue;
            }

            // Flags and exclude conditions steer the validator and were handled
            // above; there is no rule class to create for them.
            $baseName = strtolower(trim(explode(':', $ruleName, 2)[0]));

            if (in_array($baseName, self::FLAG_RULES, true)
                || in_array($baseName, self::EXCLUDE_RULES, true)) {
                continue;
            }

            $rule = $this->factory->create($ruleName);
            $rule->setAttribute($field);
            $rule->setDisplayName($this->displayName($field));
            $rule->setValue($value);
            $rule->setData($this->data);

            // Run the validation
            if (!$rule->passes()) {
                $this->errors->add($field, $this->messageFor($field, $baseName, $rule));
                $this->failedRules[$field][] = $baseName;

                if ($bail) {
                    break;
                }
            }
        }

        foreach ($inlineRules as $rule) {
            if ($this->runInlineRule($rule, $field, $value) && $bail) {
                break;
            }
        }
    }

    /**
     * Run a rule given as an object or a closure.
     *
     * Three shapes are accepted, because all three read naturally at a call
     * site and refusing two of them would only make the caller wrap one in
     * another:
     *
     *   fn ($attribute, $value, $fail) => ...     a closure
     *   new MyRule()                              validate($attribute, $value, $fail)
     *   new MyRule()                              an AbstractRule subclass
     *
     * @return bool Whether it failed.
     */
    protected function runInlineRule(object $rule, string $field, mixed $value): bool
    {
        $failed = false;

        $fail = function (string $message = '') use ($field, &$failed): void {
            $this->errors->add($field, $message !== '' ? $message : "The {$field} field is invalid.");

            $failed = true;
        };

        if ($rule instanceof \Closure) {
            $rule($field, $value, $fail);

            return $failed;
        }

        if (method_exists($rule, 'validate')) {
            $rule->validate($field, $value, $fail);

            return $failed;
        }

        if ($rule instanceof Rules\AbstractRule) {
            $rule->setAttribute($field);
            $rule->setDisplayName($this->displayName($field));
            $rule->setValue($value);
            $rule->setData($this->data);

            if (! $rule->passes()) {
                $baseName = strtolower(basename(str_replace('\\', '/', $rule::class)));

                $this->errors->add($field, $this->messageFor($field, $baseName, $rule));

                return true;
            }

            return false;
        }

        throw new \InvalidArgumentException(sprintf(
            'A rule given as an object must be a closure, declare validate($attribute, $value, $fail), '
            . 'or extend AbstractRule; %s does none of those.',
            $rule::class,
        ));
    }

    /**
     * Whether a field is dropped from the validated data.
     *
     * 'exclude' always drops it; the conditional forms drop it when another
     * field holds a given value, or is (not) present. An excluded field is not
     * validated either — the rules after it never run.
     *
     * @param array<int, string> $ruleNames Full rule tokens, parameters included.
     * @param array<int, string> $baseNames The same tokens with parameters stripped.
     */
    protected function shouldExclude(string $field, array $ruleNames, array $baseNames): bool
    {
        foreach (self::EXCLUDE_RULES as $exclude) {
            if (!in_array($exclude, $baseNames, true)) {
                continue;
            }

            if ($exclude === 'exclude') {
                return true;
            }

            $parameters = $this->parametersFor($exclude, $ruleNames);
            $other = (string) ($parameters[0] ?? '');

            if ($other === '') {
                continue;
            }

            $matches = match ($exclude) {
                'exclude_if'      => $this->otherHasValue($other, array_slice($parameters, 1)),
                'exclude_unless'  => !$this->otherHasValue($other, array_slice($parameters, 1)),
                'exclude_with'    => Arr::has($this->data, $other),
                'exclude_without' => !Arr::has($this->data, $other),
                default           => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * The parameters given to one rule in a field's rule list.
     *
     * @param array<int, string> $ruleNames
     * @return array<int, string>
     */
    protected function parametersFor(string $wanted, array $ruleNames): array
    {
        foreach ($ruleNames as $ruleName) {
            $parts = explode(':', trim($ruleName), 2);

            if (strtolower(trim($parts[0])) !== $wanted) {
                continue;
            }

            return isset($parts[1]) ? explode(',', $parts[1]) : [];
        }

        return [];
    }

    /**
     * Whether another field holds any of the given values, or is filled when
     * none are given.
     *
     * @param array<int, string> $expected
     */
    protected function otherHasValue(string $field, array $expected): bool
    {
        $actual = Arr::get($this->data, $field);

        if ($expected === []) {
            return $actual !== null && $actual !== '' && $actual !== [];
        }

        foreach ($expected as $candidate) {
            if ((string) $candidate === (string) $actual) {
                return true;
            }
        }

        return false;
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

        return array_diff_key(
            array_intersect_key($this->data, $this->rules),
            $this->excluded
        );
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

    // ─── Steering the run ─────────────────────────────────

    /**
     * Stop the whole run at the first field that fails.
     *
     * Different from bail(), which stops one field at its first failing rule
     * and carries on to the next field. This is for when the first problem is
     * the only one worth reporting — a queued job about to do expensive work,
     * or an API that answers with one error.
     */
    public function stopOnFirstFailure(bool $stop = true): static
    {
        $this->stopOnFirstFailure = $stop;

        return $this;
    }

    /**
     * Run a check of your own once the rules have run.
     *
     *     $validator->after(function ($validator) {
     *         if ($this->somethingElseIsWrong()) {
     *             $validator->errors()->add('field', 'Something else is wrong.');
     *         }
     *     });
     *
     * For what no rule can express, and for anything needing more than one
     * field to decide.
     *
     * @param callable(self): void $callback
     */
    public function after(callable $callback): static
    {
        $this->after[] = $callback;

        return $this;
    }

    /**
     * Add rules to a field only when the condition holds.
     *
     * The condition is given the data, so a rule can depend on another field's
     * value — 'reason' is required, but only when 'status' is 'rejected'.
     *
     * @param array<int, string>|string $attribute
     * @param array<int, mixed>|string $rules
     * @param (callable(array<string, mixed>): bool)|bool $condition
     */
    public function sometimes(array|string $attribute, array|string $rules, callable|bool $condition = true): static
    {
        $holds = is_callable($condition) ? $condition($this->data) : $condition;

        if (! $holds) {
            return $this;
        }

        foreach ((array) $attribute as $field) {
            $existing = $this->rules[$field] ?? [];

            $existing = is_array($existing) ? $existing : explode('|', $existing);
            $added = is_array($rules) ? $rules : explode('|', $rules);

            $this->rules[$field] = array_values(array_merge($existing, $added));
        }

        // Rules changed, so whatever was decided before no longer stands.
        return $this->reset();
    }

    // ─── Adjusting the run ────────────────────────────────

    /**
     * Replace the rules outright.
     *
     * @param array<string, string|array<int, mixed>> $rules
     */
    public function setRules(array $rules): static
    {
        $this->rules = $rules;

        return $this->reset();
    }

    /**
     * Add rules, replacing any already set for the same field.
     *
     * @param array<string, string|array<int, mixed>> $rules
     */
    public function addRules(array $rules): static
    {
        foreach ($rules as $field => $fieldRules) {
            $this->rules[$field] = $fieldRules;
        }

        return $this->reset();
    }

    /**
     * Add rules onto whatever a field already has.
     *
     * @param array<string, string|array<int, mixed>> $rules
     */
    public function appendRules(array $rules): static
    {
        foreach ($rules as $field => $fieldRules) {
            $existing = $this->rules[$field] ?? [];
            $existing = is_array($existing) ? $existing : explode('|', $existing);
            $added = is_array($fieldRules) ? $fieldRules : explode('|', $fieldRules);

            $this->rules[$field] = array_values(array_merge($existing, $added));
        }

        return $this->reset();
    }

    /** Validate different data with the same rules. */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this->reset();
    }

    /** One field's value. */
    public function getValue(string $field, mixed $default = null): mixed
    {
        return Arr::get($this->data, $field, $default);
    }

    /** Change one field's value before the rules see it. */
    public function setValue(string $field, mixed $value): static
    {
        Arr::set($this->data, $field, $value);

        return $this->reset();
    }

    /** Whether a field carries a rule, by base name. */
    public function hasRule(string $field, array|string $rules): bool
    {
        $given = $this->rules[$field] ?? [];
        $given = is_array($given) ? $given : explode('|', $given);

        $names = [];

        foreach ($given as $rule) {
            if (is_object($rule) && ! $rule instanceof \Stringable) {
                continue;
            }

            $names[] = strtolower(trim(explode(':', trim((string) $rule), 2)[0]));
        }

        foreach ((array) $rules as $wanted) {
            if (in_array(strtolower($wanted), $names, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add an error from outside the rules.
     *
     * What an after() callback calls, and what a controller reaches for when
     * something only it can check has gone wrong.
     */
    public function addFailure(string $field, string $message): static
    {
        $this->errors->add($field, $message);

        return $this;
    }

    /**
     * Friendlier names for fields in messages.
     *
     * 'dob' reads badly in "The dob field is required"; this is where it
     * becomes "date of birth".
     *
     * @param array<string, string> $attributes
     */
    public function setAttributeNames(array $attributes): static
    {
        $this->attributeNames = array_merge($this->attributeNames, $attributes);

        return $this;
    }

    /** Laravel's name for {@see setAttributeNames()}. */
    public function addCustomAttributes(array $attributes): static
    {
        return $this->setAttributeNames($attributes);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->attributeNames;
    }

    /**
     * Add messages after construction.
     *
     * @param array<string, string> $messages
     */
    public function setCustomMessages(array $messages): static
    {
        $this->messages->setMultiple($messages);

        return $this;
    }

    /**
     * What to say when a rule fails on a field.
     *
     * A rule carries the sentence it fails with, so it has one without the
     * application saying anything; an override replaces that sentence, and
     * either way the field is named the way {@see setAttributeNames()} asked.
     */
    protected function messageFor(string $field, string $rule, Rules\AbstractRule $failed): string
    {
        $override = $this->messages->resolve($field, $rule);

        if ($override === null) {
            return $failed->message();
        }

        return str_replace(
            ['{attribute}', ':attribute'],
            $this->displayName($field),
            $override
        );
    }

    /**
     * What to call a field in a message.
     *
     * The name may be given for the exact field or, when the field came from
     * a wildcard, for the pattern that produced it — so one line covers every
     * entry of a list rather than one per index.
     */
    protected function displayName(string $field): string
    {
        foreach ($this->attributeNames as $key => $name) {
            if (Messages::keyMatches((string) $key, $field)) {
                return $name;
            }
        }

        return $field;
    }

    /** Anything decided before the rules or data changed no longer stands. */
    protected function reset(): static
    {
        $this->hasValidated = false;
        $this->errors = new ErrorBag();
        $this->excluded = [];
        $this->failedRules = [];

        return $this;
    }

    // ─── Reading the result ───────────────────────────────

    /**
     * The fields that passed, with their values.
     *
     * @return array<string, mixed>
     */
    public function valid(): array
    {
        return $this->safe();
    }

    /**
     * The fields that failed, with the values they were given.
     *
     * Useful when building a response by hand: the errors say what was wrong,
     * and this says what was sent.
     *
     * @return array<string, mixed>
     */
    public function invalid(): array
    {
        if (! $this->hasValidated) {
            $this->validate();
        }

        $invalid = [];

        foreach (array_keys($this->errors->all()) as $field) {
            if (Arr::has($this->data, $field)) {
                Arr::set($invalid, $field, Arr::get($this->data, $field));
            }
        }

        return $invalid;
    }

    /**
     * Which rules failed, per field.
     *
     * Keyed field => list of rule names, so a caller can branch on the kind of
     * failure rather than on the wording of a message.
     *
     * @return array<string, array<int, string>>
     */
    public function failed(): array
    {
        if (! $this->hasValidated) {
            $this->validate();
        }

        return $this->failedRules;
    }

    /** The error bag, under Laravel's name for it. */
    public function getMessageBag(): ErrorBag
    {
        if (! $this->hasValidated) {
            $this->validate();
        }

        return $this->errors;
    }

    /**
     * The validated data, without throwing.
     *
     * validated() throws so a controller can use it as a guard. This is for
     * the cases that want to look at both halves — what passed and what did
     * not — which is most of them once the response is being built by hand.
     */
    public function safe(): array
    {
        if (! $this->hasValidated) {
            $this->validate();
        }

        $expanded = $this->expandWildcards($this->rules);

        $safe = [];

        foreach (array_keys($expanded) as $field) {
            if (isset($this->excluded[$field]) || $this->errors->has($field)) {
                continue;
            }

            if (Arr::has($this->data, $field)) {
                Arr::set($safe, $field, Arr::get($this->data, $field));
            }
        }

        return $safe;
    }

    /** Whether the run passed, without running the after() callbacks twice. */
    public function whenPasses(callable $callback): static
    {
        if ($this->passes()) {
            $callback($this);
        }

        return $this;
    }

    public function whenFails(callable $callback): static
    {
        if ($this->fails()) {
            $callback($this);
        }

        return $this;
    }
}