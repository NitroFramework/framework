<?php

namespace Tests\Unit\Validation;

use Nitro\Validation\Rule;
use Nitro\Validation\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The validator accepts array-style rule lists with Rule:: builders alongside
 * plain string rules (Laravel parity).
 */
class RuleArrayTest extends TestCase
{
    public function test_rule_in_builds_token(): void
    {
        $this->assertSame('in:admin,editor', Rule::in(['admin', 'editor']));
    }

    public function test_rule_unique_builds_token(): void
    {
        $this->assertSame('unique:users,email', Rule::unique('users', 'email'));
        $this->assertSame('unique:users,id', Rule::unique('users'));
    }

    public function test_array_rules_pass_for_valid_data(): void
    {
        $v = new Validator(
            ['role' => 'admin', 'email' => 'a@b.c'],
            [
                'role'  => ['required', Rule::in(['admin', 'editor'])],
                'email' => ['required', 'email'],
            ],
        );

        $this->assertTrue($v->validate());
    }

    public function test_array_rules_fail_and_report_errors(): void
    {
        $v = new Validator(
            ['role' => 'ghost', 'email' => 'nope'],
            [
                'role'  => ['required', Rule::in(['admin', 'editor'])],
                'email' => ['required', 'email'],
            ],
        );

        $this->assertFalse($v->validate());
        $this->assertTrue($v->errors()->has('role'));
        $this->assertTrue($v->errors()->has('email'));
    }

    public function test_pipe_string_rules_still_work(): void
    {
        $v = new Validator(
            ['email' => 'a@b.c'],
            ['email' => 'required|email'],
        );

        $this->assertTrue($v->validate());
    }
}
