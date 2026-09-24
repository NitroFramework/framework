<?php

namespace Tests\Unit\Validation;

use Nitro\Validation\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Reading a result apart from its messages, and adjusting a run in flight.
 *
 * A validator that only answers passes/fails and a bag of sentences is enough
 * for a form and nothing else. A controller that wants to save what was valid,
 * a rule that depends on another field's value, a package adding a rule to an
 * application's request — each needs the run itself to be readable.
 */
class ValidatorApiTest extends TestCase
{
    // ─── Reading the result ───────────────────────────────

    public function test_valid_and_invalid_split_the_data(): void
    {
        $validator = new Validator(
            ['a' => 'ok', 'b' => ''],
            ['a' => 'required', 'b' => 'required'],
        );

        $this->assertSame(['a' => 'ok'], $validator->valid());
        $this->assertSame(['b' => ''], $validator->invalid());
    }

    public function test_failed_names_the_rule_not_the_message(): void
    {
        $validator = new Validator(['n' => 'x'], ['n' => 'integer']);

        $this->assertSame(['n' => ['integer']], $validator->failed());
    }

    public function test_failed_lists_every_rule_that_failed(): void
    {
        $validator = new Validator(['n' => 'x'], ['n' => 'integer|min:5']);

        $this->assertSame(['n' => ['integer', 'min']], $validator->failed());
    }

    public function test_failed_is_empty_when_everything_passed(): void
    {
        $this->assertSame([], (new Validator(['n' => 5], ['n' => 'integer']))->failed());
    }

    public function test_failed_works_through_a_wildcard(): void
    {
        $validator = new Validator(['i' => [['q' => 'x']]], ['i.*.q' => 'integer']);

        $this->assertSame(['i.0.q' => ['integer']], $validator->failed());
    }

    public function test_get_message_bag_is_the_error_bag(): void
    {
        $validator = new Validator(['a' => ''], ['a' => 'required']);

        $this->assertSame(['a'], array_keys($validator->getMessageBag()->all()));
    }

    // ─── Adjusting the run ────────────────────────────────

    public function test_set_rules_replaces_them(): void
    {
        $validator = new Validator(['a' => ''], ['a' => 'required']);
        $validator->setRules(['a' => 'nullable']);

        $this->assertTrue($validator->passes());
    }

    public function test_add_rules_replaces_one_field(): void
    {
        $validator = new Validator(['a' => 'x', 'b' => ''], ['a' => 'required']);
        $validator->addRules(['b' => 'required']);

        $this->assertTrue($validator->fails());
    }

    public function test_append_rules_adds_to_what_is_there(): void
    {
        $validator = new Validator(['n' => 'x'], ['n' => 'required']);
        $validator->appendRules(['n' => 'integer']);

        $this->assertSame(['integer'], $validator->failed()['n']);
    }

    public function test_set_data_reruns_against_the_new_data(): void
    {
        $validator = new Validator(['a' => ''], ['a' => 'required']);

        $this->assertTrue($validator->fails());

        $validator->setData(['a' => 'now filled']);

        $this->assertTrue($validator->passes());
    }

    public function test_get_value_and_set_value_reach_through_dots(): void
    {
        $validator = new Validator(['user' => ['name' => 'Ada']], ['user.name' => 'required']);

        $this->assertSame('Ada', $validator->getValue('user.name'));

        $validator->setValue('user.name', 'Bob');

        $this->assertSame('Bob', $validator->getValue('user.name'));
        $this->assertSame('fallback', $validator->getValue('nope', 'fallback'));
    }

    public function test_has_rule_asks_by_base_name(): void
    {
        $validator = new Validator([], [
            'email' => 'required|email',
            'age' => ['integer', 'min:18'],
        ]);

        $this->assertTrue($validator->hasRule('email', 'required'));
        $this->assertFalse($validator->hasRule('email', 'integer'));
        $this->assertTrue($validator->hasRule('age', 'min'));
        $this->assertTrue($validator->hasRule('age', ['nope', 'integer']));
        $this->assertFalse($validator->hasRule('absent', 'required'));
    }

    public function test_add_failure_adds_an_error_of_its_own(): void
    {
        $validator = new Validator(['a' => 'x'], ['a' => 'required']);
        $validator->addFailure('a', 'Not allowed.');

        $this->assertTrue($validator->errors()->has('a'));
    }

    public function test_attribute_names_are_recorded(): void
    {
        $validator = new Validator([], []);
        $validator->setAttributeNames(['dob' => 'date of birth']);
        $validator->addCustomAttributes(['tel' => 'telephone']);

        $this->assertSame(
            ['dob' => 'date of birth', 'tel' => 'telephone'],
            $validator->attributes(),
        );
    }

    // ─── What a message says ──────────────────────────────

    public function test_a_rule_supplies_its_own_message(): void
    {
        $validator = new Validator(['a' => ''], ['a' => 'required']);

        $this->assertTrue($validator->fails());
        $this->assertSame('The a field is required.', $validator->errors()->first('a'));
    }

    public function test_a_message_given_at_construction_is_used(): void
    {
        $validator = new Validator(['a' => ''], ['a' => 'required'], ['a.required' => 'We need that.']);

        $this->assertTrue($validator->fails());
        $this->assertSame('We need that.', $validator->errors()->first('a'));
    }

    public function test_custom_messages_can_be_set_after_construction(): void
    {
        $validator = new Validator(['a' => ''], ['a' => 'required']);
        $validator->setCustomMessages(['a.required' => 'We need that.']);

        $this->assertTrue($validator->fails());
        $this->assertSame('We need that.', $validator->errors()->first('a'));
    }

    public function test_a_message_may_be_given_for_a_rule_everywhere(): void
    {
        $validator = new Validator(
            ['a' => '', 'b' => ''],
            ['a' => 'required', 'b' => 'required'],
            ['required' => 'Please fill this in.'],
        );

        $validator->fails();

        $this->assertSame('Please fill this in.', $validator->errors()->first('a'));
        $this->assertSame('Please fill this in.', $validator->errors()->first('b'));
    }

    public function test_the_more_specific_message_wins(): void
    {
        $validator = new Validator(
            ['a' => '', 'b' => ''],
            ['a' => 'required', 'b' => 'required'],
            ['required' => 'Please fill this in.', 'a.required' => 'We especially need a.'],
        );

        $validator->fails();

        $this->assertSame('We especially need a.', $validator->errors()->first('a'));
        $this->assertSame('Please fill this in.', $validator->errors()->first('b'));
    }

    public function test_a_message_may_cover_a_whole_list_with_a_wildcard(): void
    {
        $validator = new Validator(
            ['items' => [['qty' => 'x'], ['qty' => 'y']]],
            ['items.*.qty' => 'integer'],
            ['items.*.qty.integer' => 'Each quantity must be a whole number.'],
        );

        $validator->fails();

        $this->assertSame(
            'Each quantity must be a whole number.',
            $validator->errors()->first('items.0.qty'),
        );

        $this->assertSame(
            'Each quantity must be a whole number.',
            $validator->errors()->first('items.1.qty'),
        );
    }

    public function test_a_wildcard_message_does_not_reach_past_a_dot(): void
    {
        $validator = new Validator(
            ['items' => [['lines' => [['qty' => 'x']]]]],
            ['items.*.lines.*.qty' => 'integer'],
            ['items.*.qty.integer' => 'Should not be used.'],
        );

        $validator->fails();

        $this->assertSame(
            'The items.0.lines.0.qty must be an integer.',
            $validator->errors()->first('items.0.lines.0.qty'),
        );
    }

    // ─── Naming the field ─────────────────────────────────

    public function test_a_friendly_name_reaches_a_rules_own_message(): void
    {
        $validator = new Validator(['dob' => ''], ['dob' => 'required']);
        $validator->setAttributeNames(['dob' => 'date of birth']);

        $validator->fails();

        $this->assertSame(
            'The date of birth field is required.',
            $validator->errors()->first('dob'),
        );
    }

    public function test_a_friendly_name_reaches_a_custom_message(): void
    {
        $validator = new Validator(['dob' => ''], ['dob' => 'required'], [
            'required' => 'We need your :attribute.',
        ]);

        $validator->setAttributeNames(['dob' => 'date of birth']);

        $validator->fails();

        $this->assertSame(
            'We need your date of birth.',
            $validator->errors()->first('dob'),
        );
    }

    public function test_a_friendly_name_may_be_given_for_a_wildcard(): void
    {
        $validator = new Validator(['items' => [['qty' => 'x']]], ['items.*.qty' => 'integer']);
        $validator->setAttributeNames(['items.*.qty' => 'quantity']);

        $validator->fails();

        $this->assertSame(
            'The quantity must be an integer.',
            $validator->errors()->first('items.0.qty'),
        );
    }
}
