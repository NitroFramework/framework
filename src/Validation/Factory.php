<?php

namespace Nitro\Validation;

use Closure;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Support\Str;

/**
 * Makes validators, and holds the rules an application adds to all of them.
 *
 *     Validator::extend('uppercase', fn ($attribute, $value) => strtoupper($value) === $value,
 *         'The :attribute must be uppercase.');
 *
 *     $validator = Validator::make($data, ['code' => 'required|uppercase']);
 *
 * A rule added here is handed to every validator made afterwards, which is
 * what makes it usable in a form request or request()->validate() as much as
 * in a validator built by hand.
 */
class Factory
{
    /** @var array<string, Closure> Rules added by name. */
    protected array $extensions = [];

    /** @var array<string, Closure> Rules that also run on an empty field. */
    protected array $implicitExtensions = [];

    /** @var array<string, Closure> Rules whose parameters name other fields. */
    protected array $dependentExtensions = [];

    /** @var array<string, Closure> Message rewrites, by rule. */
    protected array $replacers = [];

    /** @var array<string, string> Messages the added rules fail with. */
    protected array $fallbackMessages = [];

    /**
     * Builds the validator, in place of `new Validator(...)`.
     *
     * @var (Closure(array, array, array): Validator)|null
     */
    protected ?Closure $resolver = null;

    /**
     * @param ClassResolver|null $classes Builds extensions named by class.
     *                                    Without one, the class is constructed
     *                                    with no arguments.
     */
    public function __construct(protected ?ClassResolver $classes = null)
    {
    }

    /**
     * A validator for this data, with every added rule available to it.
     *
     * @param array<string, mixed>        $data
     * @param array<string, mixed>        $rules
     * @param array<string, string>       $messages
     * @param array<string, string>       $attributes Names to call the fields in messages.
     */
    public function make(array $data, array $rules, array $messages = [], array $attributes = []): Validator
    {
        $validator = $this->resolve($data, $rules, $messages);

        if ($attributes !== []) {
            $validator->addCustomAttributes($attributes);
        }

        $this->addExtensions($validator);

        return $validator;
    }

    /**
     * Validate, returning the validated data or throwing.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @param  array<string, string> $messages
     * @param  array<string, string> $attributes
     * @return array<string, mixed>
     * @throws ValidationException When the data fails.
     */
    public function validate(array $data, array $rules, array $messages = [], array $attributes = []): array
    {
        return $this->make($data, $rules, $messages, $attributes)->validated();
    }

    /**
     * Add a rule by name.
     *
     * @param Closure|string $extension fn ($attribute, $value, $parameters, $validator): bool,
     *                                  or 'Class' / 'Class@method' (validate() by default).
     */
    public function extend(string $rule, Closure|string $extension, ?string $message = null): void
    {
        $this->extensions[$rule] = $this->callable($extension, 'validate');

        if ($message !== null) {
            $this->fallbackMessages[$rule] = $message;
        }
    }

    /**
     * Add a rule that runs even when the field is empty or missing, the way
     * 'required' does.
     */
    public function extendImplicit(string $rule, Closure|string $extension, ?string $message = null): void
    {
        $this->implicitExtensions[$rule] = $this->callable($extension, 'validate');

        if ($message !== null) {
            $this->fallbackMessages[$rule] = $message;
        }
    }

    /**
     * Add a rule whose parameters name other fields.
     *
     *     Validator::extendDependent('gte_field', fn ($attribute, $value, $parameters, $validator)
     *         => $value >= $validator->getValue($parameters[0]));
     *
     *     ['items.*.max' => 'gte_field:items.*.min']
     *
     * On a wildcard field, each '*' in the parameters becomes the key that
     * field matched, so items.2.max is compared with items.2.min.
     */
    public function extendDependent(string $rule, Closure|string $extension, ?string $message = null): void
    {
        $this->dependentExtensions[$rule] = $this->callable($extension, 'validate');

        if ($message !== null) {
            $this->fallbackMessages[$rule] = $message;
        }
    }

    /**
     * Rewrite a rule's message when it fails, for placeholders of its own.
     *
     * @param Closure|string $replacer fn ($message, $attribute, $rule, $parameters, $validator): string,
     *                                 or 'Class' / 'Class@method' (replace() by default).
     */
    public function replacer(string $rule, Closure|string $replacer): void
    {
        $this->replacers[$rule] = $this->callable($replacer, 'replace');
    }

    /**
     * Build validators with this instead, for an application that extends
     * Validator.
     *
     * @param Closure(array, array, array): Validator $resolver
     */
    public function resolver(Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    /** @return array<string, Closure> */
    public function getExtensions(): array
    {
        return $this->extensions;
    }

    /** @return array<string, Closure> */
    public function getImplicitExtensions(): array
    {
        return $this->implicitExtensions;
    }

    /** @return array<string, Closure> */
    public function getDependentExtensions(): array
    {
        return $this->dependentExtensions;
    }

    /** @return array<string, Closure> */
    public function getReplacers(): array
    {
        return $this->replacers;
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, mixed>  $rules
     * @param array<string, string> $messages
     */
    protected function resolve(array $data, array $rules, array $messages): Validator
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($data, $rules, $messages);
        }

        return new Validator($data, $rules, $messages);
    }

    protected function addExtensions(Validator $validator): void
    {
        $validator->addExtensions($this->extensions);
        $validator->addImplicitExtensions($this->implicitExtensions);
        $validator->addDependentExtensions($this->dependentExtensions);
        $validator->addReplacers($this->replacers);
        $validator->setFallbackMessages($this->fallbackMessages);
    }

    /**
     * A closure for an extension or replacer given by class, built when it
     * first runs.
     */
    protected function callable(Closure|string $callback, string $defaultMethod): Closure
    {
        if ($callback instanceof Closure) {
            return $callback;
        }

        [$class, $method] = Str::parseCallback($callback, $defaultMethod);

        return function (...$arguments) use ($class, $method): mixed {
            $instance = $this->classes !== null ? $this->classes->resolve($class) : new $class();

            return $instance->{$method}(...$arguments);
        };
    }
}
