<?php

namespace Tests\Unit\Validation;

use Nitro\Container\Container;
use Nitro\Container\ContainerClassResolver;
use Nitro\Validation\Factory;
use Nitro\Validation\ValidationException;
use Nitro\Validation\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Making validators, and rules an application adds to all of them.
 */
class ValidationFactoryTest extends TestCase
{
    private Factory $factory;

    /** The global container as the test found it, put back afterwards. */
    private ?Container $previous = null;

    protected function setUp(): void
    {
        $this->previous = Container::hasInstance() ? Container::getInstance() : null;

        $this->factory = new Factory(new ContainerClassResolver(new Container()));
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous ?? new Container());
    }

    public function test_make_builds_a_validator(): void
    {
        $validator = $this->factory->make(['email' => 'nope'], ['email' => 'required|email']);

        $this->assertInstanceOf(Validator::class, $validator);
        $this->assertTrue($validator->fails());
    }

    public function test_make_names_fields_the_way_it_is_told(): void
    {
        $validator = $this->factory->make(['dob' => ''], ['dob' => 'required'], [], ['dob' => 'date of birth']);

        $validator->validate();

        $this->assertStringContainsString('date of birth', $validator->errors()->first('dob'));
    }

    public function test_validate_returns_the_validated_data(): void
    {
        $this->assertSame(
            ['name' => 'Ada'],
            $this->factory->validate(['name' => 'Ada', 'extra' => 1], ['name' => 'required']),
        );
    }

    public function test_validate_throws_on_failure(): void
    {
        $this->expectException(ValidationException::class);

        $this->factory->validate([], ['name' => 'required']);
    }

    // ─── Extensions ───────────────────────────────────────

    public function test_an_extension_is_a_rule_by_name(): void
    {
        $this->factory->extend('uppercase', fn ($attribute, $value) => strtoupper($value) === $value, 'The :attribute must be uppercase.');

        $passes = $this->factory->make(['code' => 'ABC'], ['code' => 'required|uppercase']);
        $fails = $this->factory->make(['code' => 'abc'], ['code' => 'required|uppercase']);

        $this->assertTrue($passes->passes());
        $this->assertTrue($fails->fails());
        $this->assertSame('The code must be uppercase.', $fails->errors()->first('code'));
    }

    public function test_an_extension_receives_its_parameters_and_the_validator(): void
    {
        $seen = null;

        $this->factory->extend('divisible', function ($attribute, $value, $parameters, $validator) use (&$seen): bool {
            $seen = [$attribute, $parameters, $validator instanceof Validator];

            return $value % (int) $parameters[0] === 0;
        });

        $this->assertTrue($this->factory->make(['n' => 9], ['n' => 'divisible:3'])->passes());
        $this->assertSame(['n', ['3'], true], $seen);
        $this->assertTrue($this->factory->make(['n' => 10], ['n' => 'divisible:3'])->fails());
    }

    public function test_an_extension_without_a_message_says_something_plain(): void
    {
        $this->factory->extend('never', fn () => false);

        $validator = $this->factory->make(['a' => 'x'], ['a' => 'never']);
        $validator->validate();

        $this->assertSame('The a field is invalid.', $validator->errors()->first('a'));
    }

    public function test_a_message_given_to_the_validator_wins_over_the_extensions_own(): void
    {
        $this->factory->extend('never', fn () => false, 'registered');

        $validator = $this->factory->make(['a' => 'x'], ['a' => 'never'], ['a.never' => 'given']);
        $validator->validate();

        $this->assertSame('given', $validator->errors()->first('a'));
    }

    public function test_an_extension_can_be_a_class(): void
    {
        $this->factory->extend('even', EvenRule::class);
        $this->factory->extend('odd', EvenRule::class . '@odd');

        $this->assertTrue($this->factory->make(['n' => 4], ['n' => 'even'])->passes());
        $this->assertTrue($this->factory->make(['n' => 3], ['n' => 'even'])->fails());
        $this->assertTrue($this->factory->make(['n' => 3], ['n' => 'odd'])->passes());
    }

    public function test_an_ordinary_extension_does_not_run_on_an_empty_field(): void
    {
        $this->factory->extend('never', fn () => false);

        $this->assertTrue($this->factory->make(['a' => ''], ['a' => 'never'])->passes());
    }

    public function test_an_implicit_extension_runs_on_an_empty_field(): void
    {
        $this->factory->extendImplicit('present_or_default', fn ($attribute, $value) => $value !== '', 'Give :attribute.');

        $validator = $this->factory->make(['a' => ''], ['a' => 'present_or_default']);

        $this->assertTrue($validator->fails());
        $this->assertSame('Give a.', $validator->errors()->first('a'));
    }

    /** As in Laravel: a rule the framework ships is not replaced by an extension of the same name. */
    public function test_a_built_in_rule_wins_over_an_extension_of_the_same_name(): void
    {
        $this->factory->extend('email', fn () => true);

        $this->assertTrue($this->factory->make(['e' => 'not an email'], ['e' => 'email'])->fails());
    }

    // ─── Dependent extensions ─────────────────────────────

    public function test_a_dependent_extension_reads_the_matching_item_of_a_list(): void
    {
        $seen = [];

        $this->factory->extendDependent('gte_field', function ($attribute, $value, $parameters, $validator) use (&$seen): bool {
            $seen[$attribute] = $parameters[0];

            return $value >= $validator->getValue($parameters[0]);
        }, 'The :attribute is below its minimum.');

        $validator = $this->factory->make(
            ['items' => [['min' => 1, 'max' => 5], ['min' => 9, 'max' => 3]]],
            ['items.*.max' => 'gte_field:items.*.min'],
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(['items.0.max' => 'items.0.min', 'items.1.max' => 'items.1.min'], $seen);
        $this->assertFalse($validator->errors()->has('items.0.max'));
        $this->assertSame('The items.1.max is below its minimum.', $validator->errors()->first('items.1.max'));
    }

    public function test_a_dependent_extension_fills_each_wildcard_level_in_order(): void
    {
        $seen = null;

        $this->factory->extendDependent('probe', function ($attribute, $value, $parameters) use (&$seen): bool {
            $seen = $parameters;

            return true;
        });

        $this->factory->make(
            ['orders' => ['a' => ['lines' => [7 => ['qty' => 1]]]]],
            ['orders.*.lines.*.qty' => 'probe:orders.*.lines.*.stock,fixed'],
        )->validate();

        $this->assertSame(['orders.a.lines.7.stock', 'fixed'], $seen);
    }

    /** Only a dependent rule has its parameters rewritten. */
    public function test_an_ordinary_extension_keeps_its_asterisks(): void
    {
        $seen = null;

        $this->factory->extend('probe', function ($attribute, $value, $parameters) use (&$seen): bool {
            $seen = $parameters[0];

            return true;
        });

        $this->factory->make(['items' => [['a' => 1]]], ['items.*.a' => 'probe:items.*.b'])->validate();

        $this->assertSame('items.*.b', $seen);
    }

    // ─── Replacers ────────────────────────────────────────

    public function test_a_replacer_fills_an_extensions_own_placeholders(): void
    {
        $this->factory->extend('divisible', fn ($attribute, $value, $parameters) => $value % (int) $parameters[0] === 0,
            'The :attribute must divide by :divisor.');
        $this->factory->replacer('divisible', fn ($message, $attribute, $rule, $parameters) => str_replace(':divisor', $parameters[0], $message));

        $validator = $this->factory->make(['n' => 10], ['n' => 'divisible:3']);
        $validator->validate();

        $this->assertSame('The n must divide by 3.', $validator->errors()->first('n'));
    }

    public function test_a_replacer_applies_to_a_built_in_rule_too(): void
    {
        $this->factory->replacer('required', fn ($message) => strtoupper($message));

        $validator = $this->factory->make([], ['name' => 'required']);
        $validator->validate();

        $message = $validator->errors()->first('name');

        $this->assertSame(strtoupper($message), $message);
    }

    // ─── Resolver and helper ──────────────────────────────

    public function test_a_resolver_builds_the_validator(): void
    {
        $this->factory->resolver(fn ($data, $rules, $messages) => new CustomValidator($data, $rules, $messages));

        $this->assertInstanceOf(CustomValidator::class, $this->factory->make([], []));
    }

    public function test_the_helper_returns_the_factory_with_no_arguments(): void
    {
        $this->assertInstanceOf(Factory::class, validator());
    }

    /** The point of one factory: a rule added once reaches validators built anywhere. */
    public function test_an_extension_reaches_validators_the_helper_builds(): void
    {
        Container::setInstance($container = new Container());
        $container->instance(Factory::class, $this->factory);

        $this->factory->extend('uppercase', fn ($attribute, $value) => strtoupper($value) === $value);

        $this->assertTrue(validator(['code' => 'abc'], ['code' => 'uppercase'])->fails());
    }
}

class EvenRule
{
    public function validate(string $attribute, mixed $value): bool
    {
        return $value % 2 === 0;
    }

    public function odd(string $attribute, mixed $value): bool
    {
        return $value % 2 === 1;
    }
}

class CustomValidator extends Validator
{
}
