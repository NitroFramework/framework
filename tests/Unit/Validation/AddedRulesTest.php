<?php

namespace Tests\Unit\Validation;

use Nitro\Database\Connection;
use Nitro\Database\DB;
use Nitro\Validation\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The rules added to close the gap with what an application actually writes.
 *
 * Each case pins the decision that is easy to get wrong, rather than the happy
 * path: what an absent value does, what a falsy-but-present value does, and
 * what a rule that cannot be evaluated does.
 */
class AddedRulesTest extends TestCase
{
    private function passes(array $data, array $rules): bool
    {
        return (new Validator($data, $rules))->validate();
    }

    private function errorFor(array $data, array $rules, string $field): ?string
    {
        $validator = new Validator($data, $rules);
        $validator->validate();

        return $validator->errors()->first($field);
    }

    // ─── array / boolean ──────────────────────────────────

    public function test_array_accepts_an_array_and_rejects_a_scalar(): void
    {
        $this->assertTrue($this->passes(['seats' => ['a', 'b']], ['seats' => 'array']));
        $this->assertFalse($this->passes(['seats' => 'a'], ['seats' => 'array']));
    }

    public function test_boolean_accepts_the_values_a_checkbox_actually_posts(): void
    {
        foreach ([true, false, 1, 0, '1', '0'] as $value) {
            $this->assertTrue(
                $this->passes(['opt_in' => $value], ['opt_in' => 'boolean']),
                var_export($value, true) . ' should be accepted'
            );
        }
    }

    public function test_boolean_rejects_values_that_only_look_truthy(): void
    {
        // 'yes' and 'on' quietly becoming true is how a consent field records a
        // consent nobody gave.
        foreach (['yes', 'on', 'true', 2] as $value) {
            $this->assertFalse(
                $this->passes(['opt_in' => $value], ['opt_in' => 'boolean']),
                var_export($value, true) . ' should be rejected'
            );
        }
    }

    public function test_boolean_does_not_treat_false_as_absent(): void
    {
        // false and '0' are empty-ish to PHP, and are exactly what this rule is
        // for. Skipping them as "empty" would make the rule untestable.
        $this->assertTrue($this->passes(['opt_in' => false], ['opt_in' => 'boolean']));
    }

    // ─── between ──────────────────────────────────────────

    public function test_between_measures_numbers_by_value_and_strings_by_length(): void
    {
        $this->assertTrue($this->passes(['quantity' => 30], ['quantity' => 'between:1,500']));
        $this->assertFalse($this->passes(['quantity' => 900], ['quantity' => 'between:1,500']));

        $this->assertTrue($this->passes(['title' => 'Food Hygiene'], ['title' => 'between:1,500']));
        $this->assertFalse($this->passes(['title' => str_repeat('x', 600)], ['title' => 'between:1,500']));
    }

    public function test_between_counts_an_array(): void
    {
        $this->assertTrue($this->passes(['ids' => [1, 2, 3]], ['ids' => 'between:1,5']));
        $this->assertFalse($this->passes(['ids' => range(1, 9)], ['ids' => 'between:1,5']));
    }

    public function test_between_is_inclusive(): void
    {
        $this->assertTrue($this->passes(['quantity' => 1], ['quantity' => 'between:1,500']));
        $this->assertTrue($this->passes(['quantity' => 500], ['quantity' => 'between:1,500']));
    }

    // ─── same / different ─────────────────────────────────

    public function test_same_and_different(): void
    {
        $this->assertTrue($this->passes(
            ['email' => 'a@b.com', 'email_confirmation' => 'a@b.com'],
            ['email_confirmation' => 'same:email']
        ));

        $this->assertFalse($this->passes(
            ['email' => 'a@b.com', 'email_confirmation' => 'c@d.com'],
            ['email_confirmation' => 'same:email']
        ));

        $this->assertTrue($this->passes(
            ['current' => 'old', 'password' => 'new'],
            ['password' => 'different:current']
        ));

        $this->assertFalse($this->passes(
            ['current' => 'same', 'password' => 'same'],
            ['password' => 'different:current']
        ));
    }

    // ─── required_if / required_with ──────────────────────

    public function test_required_if_only_bites_when_the_other_field_matches(): void
    {
        $rules = ['purchase_order' => 'required_if:payment_method,invoice'];

        $this->assertTrue($this->passes(['payment_method' => 'card'], $rules));
        $this->assertFalse($this->passes(['payment_method' => 'invoice'], $rules));
        $this->assertTrue($this->passes(
            ['payment_method' => 'invoice', 'purchase_order' => 'PO-1'],
            $rules
        ));
    }

    public function test_required_if_accepts_several_triggers(): void
    {
        $rules = ['reason' => 'required_if:status,rejected,withdrawn'];

        $this->assertFalse($this->passes(['status' => 'withdrawn'], $rules));
        $this->assertTrue($this->passes(['status' => 'approved'], $rules));
    }

    public function test_required_with_ignores_an_empty_companion_field(): void
    {
        $rules = ['postcode' => 'required_with:address_line_1'];

        // Submitted but blank: an untouched optional address block must not
        // start demanding a postcode.
        $this->assertTrue($this->passes(['address_line_1' => ''], $rules));
        $this->assertFalse($this->passes(['address_line_1' => '1 High St'], $rules));
    }

    // ─── after / before ───────────────────────────────────

    public function test_after_compares_against_another_field(): void
    {
        $rules = ['ends_at' => 'after:starts_at'];

        $this->assertTrue($this->passes(
            ['starts_at' => '2026-01-01', 'ends_at' => '2026-02-01'],
            $rules
        ));

        $this->assertFalse($this->passes(
            ['starts_at' => '2026-03-01', 'ends_at' => '2026-02-01'],
            $rules
        ));
    }

    public function test_after_compares_against_a_date_string(): void
    {
        $this->assertTrue($this->passes(['expires_at' => '2099-01-01'], ['expires_at' => 'after:today']));
        $this->assertFalse($this->passes(['expires_at' => '2001-01-01'], ['expires_at' => 'after:today']));
    }

    public function test_before_is_the_mirror(): void
    {
        $this->assertTrue($this->passes(
            ['starts_at' => '2026-01-01', 'ends_at' => '2026-02-01'],
            ['starts_at' => 'before:ends_at']
        ));
    }

    public function test_a_date_rule_that_cannot_be_evaluated_fails(): void
    {
        // Neither a field nor a parsable date. Passing silently would report a
        // typo'd rule as a satisfied one.
        $this->assertFalse($this->passes(['ends_at' => '2026-02-01'], ['ends_at' => 'after:not_a_thing']));
    }

    public function test_an_optional_empty_field_skips_these_rules(): void
    {
        // Absent and not required: `required` reports a missing field, and one
        // missing field should produce one error, not five.
        $this->assertTrue($this->passes([], [
            'quantity' => 'between:1,10',
            'ends_at' => 'after:starts_at',
            'seats' => 'array',
        ]));
    }

    // ─── exists ───────────────────────────────────────────

    public function test_exists_checks_the_real_table(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite required');
        }

        $conn = new class([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]) extends Connection {
            protected function buildDsn(array $c): string { return 'sqlite::memory:'; }
            protected function afterConnect(\PDO $pdo): void {}
        };

        $r = new \ReflectionClass(DB::class);
        $p = $r->getProperty('connection');
        $p->setAccessible(true);
        $p->setValue(null, $conn);
        $g = $r->getProperty('grammar');
        $g->setAccessible(true);
        $g->setValue(null, new \Nitro\Database\Query\Grammar\SqliteGrammar());

        $conn->statement('CREATE TABLE courses (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT)');
        DB::table('courses')->insert(['slug' => 'food-hygiene']);

        $this->assertTrue($this->passes(['course_id' => 1], ['course_id' => 'exists:courses,id']));
        $this->assertFalse($this->passes(['course_id' => 99], ['course_id' => 'exists:courses,id']));

        // No column given: it defaults to the field's own name.
        $this->assertTrue($this->passes(['slug' => 'food-hygiene'], ['slug' => 'exists:courses']));

        DB::disconnect();
    }

    public function test_exists_refuses_an_identifier_that_is_not_an_identifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->passes(['id' => 1], ['id' => 'exists:courses;DROP TABLE courses,id']);
    }
}
