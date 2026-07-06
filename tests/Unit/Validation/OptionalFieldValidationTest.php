<?php

namespace Tests\Unit\Validation;

use Nitro\Validation\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Non-implicit rules (email, min, numeric, …) must NOT run against an empty or
 * absent optional field — only when the value is present, or when an implicit
 * rule (required, …) forces validation. Mirrors Laravel; previously `email`
 * alone would reject an omitted optional field.
 */
class OptionalFieldValidationTest extends TestCase
{
    public function test_absent_optional_field_passes(): void
    {
        $v = new Validator([], ['email' => 'email']);
        $this->assertTrue($v->validate());
    }

    public function test_empty_optional_field_passes(): void
    {
        $v = new Validator(['email' => ''], ['email' => 'email']);
        $this->assertTrue($v->validate());
    }

    public function test_present_optional_field_is_still_validated(): void
    {
        $v = new Validator(['email' => 'not-an-email'], ['email' => 'email']);
        $this->assertFalse($v->validate());
    }

    public function test_valid_present_optional_field_passes(): void
    {
        $v = new Validator(['email' => 'a@b.co'], ['email' => 'email']);
        $this->assertTrue($v->validate());
    }

    public function test_required_absent_field_still_fails(): void
    {
        $v = new Validator([], ['email' => 'required|email']);
        $this->assertFalse($v->validate());
    }

    public function test_required_empty_field_still_fails(): void
    {
        $v = new Validator(['email' => ''], ['email' => 'required|email']);
        $this->assertFalse($v->validate());
    }

    public function test_nullable_empty_field_passes(): void
    {
        $v = new Validator(['email' => ''], ['email' => 'nullable|email']);
        $this->assertTrue($v->validate());
    }

    public function test_optional_numeric_min_skipped_when_absent(): void
    {
        // A whole chain of non-implicit rules is skipped for an absent optional.
        $v = new Validator([], ['age' => 'numeric|min:18']);
        $this->assertTrue($v->validate());
    }
}
