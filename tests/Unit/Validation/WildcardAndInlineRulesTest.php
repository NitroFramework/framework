<?php

namespace Tests\Unit\Validation;

use Closure;
use Nitro\Validation\Rule;
use Nitro\Validation\Rules\AbstractRule;
use Nitro\Validation\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Validating a list of things, and rules written at the call site.
 *
 * Nested rules (user.email) already worked; wildcards did not, so an
 * application could not validate an array of anything — order lines, a
 * repeater form, a bulk import, a list of tags. The rule key simply never
 * matched, and the field went unchecked without saying so.
 */
class WildcardAndInlineRulesTest extends TestCase
{
    // ─── Wildcards ────────────────────────────────────────

    public function test_a_bad_entry_in_a_list_fails(): void
    {
        $validator = new Validator(
            ['items' => [['qty' => 'x'], ['qty' => 2]]],
            ['items.*.qty' => 'integer'],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_a_good_list_passes(): void
    {
        $validator = new Validator(
            ['items' => [['qty' => 1], ['qty' => 2]]],
            ['items.*.qty' => 'integer'],
        );

        $this->assertTrue($validator->passes());
    }

    /** The error names the entry, so a form can mark the right row. */
    public function test_the_error_names_which_entry_was_wrong(): void
    {
        $validator = new Validator(
            ['items' => [['qty' => 1], ['qty' => 'x']]],
            ['items.*.qty' => 'integer'],
        );

        $validator->validate();

        $this->assertSame(['items.1.qty'], array_keys($validator->errors()->all()));
    }

    public function test_every_bad_entry_is_reported(): void
    {
        $validator = new Validator(
            ['items' => [['qty' => 'a'], ['qty' => 'b']]],
            ['items.*.qty' => 'integer'],
        );

        $validator->validate();

        $this->assertSame(['items.0.qty', 'items.1.qty'], array_keys($validator->errors()->all()));
    }

    public function test_a_flat_list_of_values(): void
    {
        $validator = new Validator(['tags' => ['a', '', 'c']], ['tags.*' => 'required']);

        $validator->validate();

        $this->assertSame(['tags.1'], array_keys($validator->errors()->all()));
    }

    public function test_wildcards_nest(): void
    {
        $validator = new Validator(
            ['orders' => [['lines' => [['sku' => 'A'], ['sku' => '']]]]],
            ['orders.*.lines.*.sku' => 'required'],
        );

        $validator->validate();

        $this->assertSame(['orders.0.lines.1.sku'], array_keys($validator->errors()->all()));
    }

    public function test_a_keyed_array_expands_by_its_keys(): void
    {
        $validator = new Validator(
            ['prices' => ['gbp' => 10, 'usd' => 'x']],
            ['prices.*' => 'numeric'],
        );

        $validator->validate();

        $this->assertSame(['prices.usd'], array_keys($validator->errors()->all()));
    }

    /**
     * A pattern matching nothing produces no rules, which is why the list
     * itself has to be required separately when it must be there.
     */
    public function test_a_wildcard_over_nothing_makes_no_rules(): void
    {
        $this->assertTrue((new Validator(['items' => []], ['items.*.qty' => 'required']))->passes());
        $this->assertTrue((new Validator([], ['items.*.qty' => 'required']))->passes());

        $this->assertTrue(
            (new Validator([], ['items' => 'required|array', 'items.*.qty' => 'integer']))->fails(),
        );
    }

    public function test_a_wildcard_takes_an_array_of_rules(): void
    {
        $validator = new Validator(
            ['items' => [['qty' => 0]]],
            ['items.*.qty' => ['required', 'integer', 'min:1']],
        );

        $this->assertTrue($validator->fails());
    }

    // ─── Rules written at the call site ───────────────────

    public function test_a_closure_can_fail_a_field(): void
    {
        $validator = new Validator(['code' => 'abc'], ['code' => [
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (strlen((string) $value) < 5) {
                    $fail('Too short.');
                }
            },
        ]]);

        $validator->validate();

        $this->assertSame(['Too short.'], $validator->errors()->all()['code']);
    }

    public function test_a_closure_can_pass(): void
    {
        $validator = new Validator(['code' => 'abcdef'], ['code' => [
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (strlen((string) $value) < 5) {
                    $fail('Too short.');
                }
            },
        ]]);

        $this->assertTrue($validator->passes());
    }

    public function test_an_inline_rule_runs_beside_string_rules(): void
    {
        $validator = new Validator(['code' => ''], ['code' => [
            'required',
            static fn (string $a, mixed $v, Closure $fail) => $fail('Also bad.'),
        ]]);

        $validator->validate();

        $this->assertCount(2, $validator->errors()->all()['code']);
    }

    public function test_an_object_declaring_validate_is_accepted(): void
    {
        $this->assertTrue((new Validator(['name' => 'ada'], ['name' => [new UppercaseRule()]]))->fails());
        $this->assertTrue((new Validator(['name' => 'ADA'], ['name' => [new UppercaseRule()]]))->passes());
    }

    public function test_an_abstract_rule_subclass_is_accepted(): void
    {
        $this->assertTrue((new Validator(['n' => 3], ['n' => [new EvenNumberRule()]]))->fails());
        $this->assertTrue((new Validator(['n' => 4], ['n' => [new EvenNumberRule()]]))->passes());
    }

    public function test_an_inline_rule_works_inside_a_wildcard(): void
    {
        $validator = new Validator(
            ['items' => [['sku' => 'AB'], ['sku' => 'cd']]],
            ['items.*.sku' => [new UppercaseRule()]],
        );

        $validator->validate();

        $this->assertSame(['items.1.sku'], array_keys($validator->errors()->all()));
    }

    public function test_bail_stops_after_the_first_inline_failure(): void
    {
        $validator = new Validator(['n' => 'x'], ['n' => [
            'bail',
            static fn (string $a, mixed $v, Closure $fail) => $fail('One.'),
            static fn (string $a, mixed $v, Closure $fail) => $fail('Two.'),
        ]]);

        $validator->validate();

        $this->assertCount(1, $validator->errors()->all()['n']);
    }

    /** Rule::in() and friends return tokens, so they must keep casting. */
    public function test_the_rule_builders_still_work_as_tokens(): void
    {
        $validator = new Validator(
            ['role' => 'nope'],
            ['role' => ['required', Rule::in(['admin', 'user'])]],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_an_object_that_is_none_of_the_three_says_so(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('NotARule');

        (new Validator(['a' => 1], ['a' => [new NotARule()]]))->validate();
    }

    // ─── Steering the run ─────────────────────────────────

    /** Different from bail(), which stops one field and moves to the next. */
    public function test_stopOnFirstFailure_reports_one_field(): void
    {
        $validator = new Validator(['a' => '', 'b' => ''], ['a' => 'required', 'b' => 'required']);

        $validator->stopOnFirstFailure()->validate();

        $this->assertCount(1, $validator->errors()->all());
    }

    public function test_without_it_every_field_is_reported(): void
    {
        $validator = new Validator(['a' => '', 'b' => ''], ['a' => 'required', 'b' => 'required']);

        $validator->validate();

        $this->assertCount(2, $validator->errors()->all());
    }

    /** For what no rule can express, and for checks needing several fields. */
    public function test_after_can_add_an_error_of_its_own(): void
    {
        $validator = new Validator(['a' => 'x'], ['a' => 'required']);

        $validator->after(static function (Validator $validator): void {
            $validator->errors()->add('a', 'Something else is wrong.');
        });

        $this->assertTrue($validator->fails());
    }

    public function test_after_runs_even_when_the_rules_passed(): void
    {
        $ran = false;

        $validator = new Validator(['a' => 'x'], ['a' => 'required']);

        $validator->after(function () use (&$ran): void {
            $ran = true;
        });

        $validator->validate();

        $this->assertTrue($ran);
    }

    public function test_sometimes_adds_rules_only_when_the_condition_holds(): void
    {
        $rejected = new Validator(['status' => 'rejected'], ['status' => 'required']);
        $rejected->sometimes('reason', 'required', static fn (array $d): bool => $d['status'] === 'rejected');

        $this->assertTrue($rejected->fails(), 'a rejection needs a reason');

        $approved = new Validator(['status' => 'approved'], ['status' => 'required']);
        $approved->sometimes('reason', 'required', static fn (array $d): bool => $d['status'] === 'rejected');

        $this->assertTrue($approved->passes(), 'an approval does not');
    }

    public function test_whenFails_and_whenPasses(): void
    {
        $ran = [];

        (new Validator(['a' => ''], ['a' => 'required']))
            ->whenFails(function () use (&$ran): void { $ran[] = 'failed'; })
            ->whenPasses(function () use (&$ran): void { $ran[] = 'passed'; });

        $this->assertSame(['failed'], $ran);
    }

    // ─── Reading the result ───────────────────────────────

    /** validated() throws; this is for code that wants both halves. */
    public function test_safe_returns_what_passed_without_throwing(): void
    {
        $validator = new Validator(['a' => 'ok', 'b' => ''], ['a' => 'required', 'b' => 'required']);

        $this->assertSame(['a' => 'ok'], $validator->safe());
    }

    public function test_safe_keeps_the_nested_shape(): void
    {
        $validator = new Validator(['user' => ['name' => 'Ada']], ['user.name' => 'required']);

        $this->assertSame(['user' => ['name' => 'Ada']], $validator->safe());
    }

    public function test_safe_drops_only_the_entries_that_failed(): void
    {
        $validator = new Validator(
            ['items' => [['qty' => 1], ['qty' => 'x']]],
            ['items.*.qty' => 'integer'],
        );

        $this->assertSame(['items' => [['qty' => 1]]], $validator->safe());
    }
}

/** The modern shape: one method, told how to fail. */
class UppercaseRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== strtoupper((string) $value)) {
            $fail("The {$attribute} must be upper case.");
        }
    }
}

/** The framework's own base class. */
class EvenNumberRule extends AbstractRule
{
    public function passes(): bool
    {
        return is_numeric($this->value) && (int) $this->value % 2 === 0;
    }

    public function message(): string
    {
        return "The {$this->attribute} must be even.";
    }
}

class NotARule
{
}
