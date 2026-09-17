<?php

namespace Tests\Unit\Validation;

use Nitro\Validation\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the built-in rule set, one family at a time.
 */
class RuleCoverageTest extends TestCase
{
    /** @param array<string, mixed> $data */
    private function passes(array $data, string|array $rules, string $field = 'f'): bool
    {
        return ! (new Validator($data, [$field => $rules]))->fails();
    }

    private function check(mixed $value, string $rules): bool
    {
        return $this->passes(['f' => $value], $rules);
    }

    // ─── String format ────────────────────────────────────

    public function test_alpha_family(): void
    {
        $this->assertTrue($this->check('abcDEF', 'alpha'));
        $this->assertFalse($this->check('abc1', 'alpha'));

        $this->assertTrue($this->check('a-b_c1', 'alpha_dash'));
        $this->assertFalse($this->check('a b', 'alpha_dash'));

        $this->assertTrue($this->check('abc123', 'alpha_num'));
        $this->assertFalse($this->check('abc-123', 'alpha_num'));
    }

    /** Unicode letters count as letters. */
    public function test_alpha_accepts_unicode(): void
    {
        $this->assertTrue($this->check('Müller', 'alpha'));
        $this->assertTrue($this->check('Ünïcödé', 'alpha'));
    }

    public function test_ascii(): void
    {
        $this->assertTrue($this->check('plain-ascii', 'ascii'));
        $this->assertFalse($this->check('Müller', 'ascii'));
    }

    public function test_case_rules(): void
    {
        $this->assertTrue($this->check('lower', 'lowercase'));
        $this->assertFalse($this->check('Lower', 'lowercase'));

        $this->assertTrue($this->check('UPPER', 'uppercase'));
        $this->assertFalse($this->check('Upper', 'uppercase'));
    }

    public function test_network_formats(): void
    {
        $this->assertTrue($this->check('192.168.1.1', 'ip'));
        $this->assertTrue($this->check('::1', 'ip'));
        $this->assertFalse($this->check('999.1.1.1', 'ip'));

        $this->assertTrue($this->check('192.168.1.1', 'ipv4'));
        $this->assertFalse($this->check('::1', 'ipv4'));

        $this->assertTrue($this->check('::1', 'ipv6'));
        $this->assertFalse($this->check('192.168.1.1', 'ipv6'));

        $this->assertTrue($this->check('00:1B:44:11:3A:B7', 'mac_address'));
        $this->assertFalse($this->check('not-a-mac', 'mac_address'));
    }

    public function test_identifier_formats(): void
    {
        $this->assertTrue($this->check('3f2504e0-4f89-11d3-9a0c-0305e82c3301', 'uuid'));
        $this->assertFalse($this->check('nope', 'uuid'));

        $this->assertTrue($this->check('01ARZ3NDEKTSV4RRFFQ69G5FAV', 'ulid'));
        $this->assertFalse($this->check('short', 'ulid'));
    }

    public function test_json_and_hex_and_timezone(): void
    {
        $this->assertTrue($this->check('{"a":1}', 'json'));
        $this->assertFalse($this->check('{a:1}', 'json'));

        $this->assertTrue($this->check('#fff', 'hex_color'));
        $this->assertTrue($this->check('#ff00ff', 'hex_color'));
        $this->assertFalse($this->check('ff00ff', 'hex_color'));

        $this->assertTrue($this->check('Europe/London', 'timezone'));
        $this->assertFalse($this->check('Mars/Olympus', 'timezone'));
    }

    // ─── String content ───────────────────────────────────

    public function test_starts_and_ends_with(): void
    {
        $this->assertTrue($this->check('/admin/users', 'starts_with:/admin,/api'));
        $this->assertFalse($this->check('/public', 'starts_with:/admin,/api'));

        $this->assertTrue($this->check('report.pdf', 'ends_with:.pdf,.docx'));
        $this->assertFalse($this->check('report.exe', 'ends_with:.pdf,.docx'));

        $this->assertFalse($this->check('admin-x', 'doesnt_start_with:admin-'));
        $this->assertTrue($this->check('user-x', 'doesnt_start_with:admin-'));

        $this->assertFalse($this->check('a.exe', 'doesnt_end_with:.exe'));
        $this->assertTrue($this->check('a.txt', 'doesnt_end_with:.exe'));
    }

    /** contains requires every listed substring, not just one. */
    public function test_contains_requires_all(): void
    {
        $this->assertTrue($this->check('terms and privacy', 'contains:terms,privacy'));
        $this->assertFalse($this->check('terms only', 'contains:terms,privacy'));

        $this->assertTrue($this->check('clean text', 'doesnt_contain:spam,junk'));
        $this->assertFalse($this->check('some spam here', 'doesnt_contain:spam,junk'));
    }

    public function test_not_regex(): void
    {
        $this->assertTrue($this->check('user', 'not_regex:/^admin/i'));
        $this->assertFalse($this->check('AdminUser', 'not_regex:/^admin/i'));
    }

    public function test_not_in(): void
    {
        $this->assertTrue($this->check('editor', 'not_in:root,superuser'));
        $this->assertFalse($this->check('root', 'not_in:root,superuser'));
    }

    // ─── Numeric ──────────────────────────────────────────

    public function test_digit_rules(): void
    {
        $this->assertTrue($this->check('1234', 'digits:4'));
        $this->assertFalse($this->check('123', 'digits:4'));
        $this->assertFalse($this->check('12a4', 'digits:4'));

        $this->assertTrue($this->check('12345', 'digits_between:4,6'));
        $this->assertFalse($this->check('123', 'digits_between:4,6'));

        $this->assertTrue($this->check('1234', 'max_digits:4'));
        $this->assertFalse($this->check('12345', 'max_digits:4'));

        $this->assertTrue($this->check('1234', 'min_digits:4'));
        $this->assertFalse($this->check('123', 'min_digits:4'));
    }

    public function test_multiple_of(): void
    {
        $this->assertTrue($this->check(10, 'multiple_of:5'));
        $this->assertFalse($this->check(7, 'multiple_of:5'));
        $this->assertTrue($this->check(0.75, 'multiple_of:0.25'));
    }

    public function test_decimal(): void
    {
        $this->assertTrue($this->check('1.23', 'decimal:2'));
        $this->assertFalse($this->check('1.2', 'decimal:2'));
        $this->assertTrue($this->check('1.2', 'decimal:1,4'));
        $this->assertTrue($this->check('1.2345', 'decimal:1,4'));
        $this->assertFalse($this->check('1.23456', 'decimal:1,4'));
    }

    public function test_size_across_types(): void
    {
        $this->assertTrue($this->check('abcdef', 'size:6'));
        $this->assertFalse($this->check('abc', 'size:6'));
        $this->assertTrue($this->check(6, 'size:6'));
        $this->assertTrue($this->check([1, 2, 3], 'size:3'));
    }

    public function test_comparison_against_a_literal(): void
    {
        $this->assertTrue($this->check(10, 'gt:5'));
        $this->assertFalse($this->check(5, 'gt:5'));
        $this->assertTrue($this->check(5, 'gte:5'));
        $this->assertTrue($this->check(1, 'lt:5'));
        $this->assertTrue($this->check(5, 'lte:5'));
    }

    public function test_comparison_against_another_field(): void
    {
        $this->assertTrue($this->passes(['min' => 1, 'f' => 5], 'gt:min'));
        $this->assertFalse($this->passes(['min' => 9, 'f' => 5], 'gt:min'));
        $this->assertTrue($this->passes(['max' => 9, 'f' => 5], 'lt:max'));
    }

    // ─── Acceptance ───────────────────────────────────────

    public function test_accepted_and_declined(): void
    {
        foreach (['yes', 'on', '1', 1, true] as $value) {
            $this->assertTrue($this->check($value, 'accepted'), var_export($value, true));
        }

        $this->assertFalse($this->check('no', 'accepted'));

        foreach (['no', 'off', '0', 0, false] as $value) {
            $this->assertTrue($this->check($value, 'declined'), var_export($value, true));
        }

        $this->assertFalse($this->check('yes', 'declined'));
    }

    public function test_accepted_if(): void
    {
        $this->assertFalse($this->passes(['plan' => 'pro', 'f' => 'no'], 'accepted_if:plan,pro'));
        $this->assertTrue($this->passes(['plan' => 'pro', 'f' => 'yes'], 'accepted_if:plan,pro'));
        $this->assertTrue($this->passes(['plan' => 'free', 'f' => 'no'], 'accepted_if:plan,pro'));
    }

    // ─── Presence and absence ─────────────────────────────

    public function test_filled_only_applies_when_submitted(): void
    {
        $this->assertTrue($this->passes([], 'filled'));
        $this->assertFalse($this->passes(['f' => ''], 'filled'));
        $this->assertTrue($this->passes(['f' => 'x'], 'filled'));
    }

    public function test_present_allows_empty_but_requires_the_key(): void
    {
        $this->assertTrue($this->passes(['f' => ''], 'present'));
        $this->assertFalse($this->passes([], 'present'));
    }

    public function test_missing(): void
    {
        $this->assertTrue($this->passes([], 'missing'));
        $this->assertFalse($this->passes(['f' => 'x'], 'missing'));

        $this->assertFalse($this->passes(['mode' => 'create', 'f' => 'x'], 'missing_if:mode,create'));
        $this->assertTrue($this->passes(['mode' => 'update', 'f' => 'x'], 'missing_if:mode,create'));
    }

    public function test_prohibited(): void
    {
        $this->assertTrue($this->passes([], 'prohibited'));
        $this->assertFalse($this->passes(['f' => 'x'], 'prohibited'));

        $this->assertFalse($this->passes(['plan' => 'free', 'f' => 'x'], 'prohibited_if:plan,free'));
        $this->assertTrue($this->passes(['plan' => 'pro', 'f' => 'x'], 'prohibited_if:plan,free'));
    }

    public function test_prohibits(): void
    {
        $this->assertFalse($this->passes(['f' => 'x', 'name' => 'n'], 'prohibits:name'));
        $this->assertTrue($this->passes(['f' => 'x'], 'prohibits:name'));
        $this->assertTrue($this->passes(['name' => 'n'], 'prohibits:name'));
    }

    // ─── Required family ──────────────────────────────────

    public function test_required_unless(): void
    {
        $this->assertFalse($this->passes(['status' => 'rejected'], 'required_unless:status,approved'));
        $this->assertTrue($this->passes(['status' => 'approved'], 'required_unless:status,approved'));
        $this->assertTrue($this->passes(['status' => 'rejected', 'f' => 'why'], 'required_unless:status,approved'));
    }

    public function test_required_without_and_without_all(): void
    {
        $this->assertFalse($this->passes([], 'required_without:phone'));
        $this->assertTrue($this->passes(['phone' => '123'], 'required_without:phone'));

        $this->assertFalse($this->passes([], 'required_without_all:phone,username'));
        $this->assertTrue($this->passes(['phone' => '123'], 'required_without_all:phone,username'));
    }

    public function test_required_with_all(): void
    {
        $this->assertFalse($this->passes(['a' => 1, 'b' => 2], 'required_with_all:a,b'));
        $this->assertTrue($this->passes(['a' => 1], 'required_with_all:a,b'));
    }

    public function test_required_if_accepted(): void
    {
        $this->assertFalse($this->passes(['paid' => 'yes'], 'required_if_accepted:paid'));
        $this->assertTrue($this->passes(['paid' => 'no'], 'required_if_accepted:paid'));
        $this->assertTrue($this->passes(['paid' => 'yes', 'f' => 'card'], 'required_if_accepted:paid'));
    }

    public function test_required_array_keys(): void
    {
        $this->assertTrue($this->passes(
            ['f' => ['line1' => 'a', 'city' => 'b']],
            'required_array_keys:line1,city'
        ));
        $this->assertFalse($this->passes(
            ['f' => ['line1' => 'a']],
            'required_array_keys:line1,city'
        ));
    }

    // ─── Arrays ───────────────────────────────────────────

    public function test_distinct(): void
    {
        $this->assertTrue($this->check(['a', 'b'], 'distinct'));
        $this->assertFalse($this->check(['a', 'a'], 'distinct'));
        $this->assertTrue($this->check(['a', 'A'], 'distinct'));
        $this->assertFalse($this->check(['a', 'A'], 'distinct:ignore_case'));
    }

    public function test_list(): void
    {
        $this->assertTrue($this->check(['a', 'b'], 'list'));
        $this->assertFalse($this->check(['x' => 'a'], 'list'));
    }

    public function test_in_array(): void
    {
        $this->assertTrue($this->passes(['options' => ['a', 'b'], 'f' => 'a'], 'in_array:options.*'));
        $this->assertFalse($this->passes(['options' => ['a', 'b'], 'f' => 'z'], 'in_array:options.*'));
    }

    // ─── Dates ────────────────────────────────────────────

    public function test_date_comparisons_against_a_field(): void
    {
        $data = ['start' => '2026-01-01', 'f' => '2026-06-01'];

        $this->assertTrue($this->passes($data, 'after_or_equal:start'));
        $this->assertFalse($this->passes($data, 'before_or_equal:start'));
        $this->assertTrue($this->passes(['start' => '2026-06-01', 'f' => '2026-06-01'], 'after_or_equal:start'));
        $this->assertTrue($this->passes(['start' => '2026-06-01', 'f' => '2026-06-01'], 'date_equals:start'));
    }

    /** The reformatted date must equal the input, so a loose match fails. */
    public function test_date_format_is_exact(): void
    {
        $this->assertTrue($this->check('2026-01-01', 'date_format:Y-m-d'));
        $this->assertFalse($this->check('2026-1-1', 'date_format:Y-m-d'));
        $this->assertTrue($this->check('2026-01-01 10:30:00', 'date_format:Y-m-d,Y-m-d H:i:s'));
    }

    // ─── Control flow ─────────────────────────────────────

    public function test_sometimes_skips_absent_fields(): void
    {
        $this->assertTrue($this->passes([], 'sometimes|required|email'));
        $this->assertFalse($this->passes(['f' => 'nope'], 'sometimes|required|email'));
        $this->assertTrue($this->passes(['f' => 'a@b.test'], 'sometimes|required|email'));
    }

    public function test_bail_stops_at_the_first_failure(): void
    {
        $validator = new Validator(['f' => 'x'], ['f' => 'bail|numeric|min:100']);
        $validator->fails();

        $this->assertCount(1, $validator->errors()->all()['f']);
    }

    public function test_without_bail_every_rule_reports(): void
    {
        $validator = new Validator(['f' => 'x'], ['f' => 'numeric|min:100']);
        $validator->fails();

        $this->assertGreaterThan(1, count($validator->errors()->all()['f']));
    }

    public function test_exclude_drops_the_field_from_validated_data(): void
    {
        $validator = new Validator(
            ['keep' => 'a', 'drop' => 'b'],
            ['keep' => 'required', 'drop' => 'exclude']
        );

        $this->assertArrayHasKey('keep', $validator->validated());
        $this->assertArrayNotHasKey('drop', $validator->validated());
    }

    public function test_exclude_if_is_conditional(): void
    {
        $rules = ['mode' => 'required', 'extra' => 'exclude_if:mode,simple|required'];

        $simple = new Validator(['mode' => 'simple'], $rules);
        $this->assertArrayNotHasKey('extra', $simple->validated());

        $full = new Validator(['mode' => 'full', 'extra' => 'x'], $rules);
        $this->assertArrayHasKey('extra', $full->validated());
    }

    /** An excluded field is not validated, so its other rules never fire. */
    public function test_excluded_field_is_not_validated(): void
    {
        $validator = new Validator(
            ['mode' => 'simple'],
            ['extra' => 'exclude_if:mode,simple|required']
        );

        $this->assertFalse($validator->fails());
    }

    public function test_exclude_without(): void
    {
        $rules = ['f' => 'exclude_without:other'];

        $this->assertArrayNotHasKey('f', (new Validator(['f' => 'x'], $rules))->validated());
        $this->assertArrayHasKey(
            'f',
            (new Validator(['f' => 'x', 'other' => 'y'], $rules))->validated()
        );
    }
}
