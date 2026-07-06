<?php

namespace Tests\Unit\Validation;

use Nitro\Validation\Rules\Numeric;
use PHPUnit\Framework\TestCase;

class NumericRuleTest extends TestCase
{
    protected function rule(mixed $value): Numeric
    {
        $rule = new Numeric();
        $rule->setAttribute('field');
        $rule->setValue($value);
        $rule->setData([]);
        return $rule;
    }

    public function test_accepts_integer(): void
    {
        $this->assertTrue($this->rule(42)->passes());
        $this->assertTrue($this->rule(0)->passes());
        $this->assertTrue($this->rule(-5)->passes());
    }

    public function test_accepts_float(): void
    {
        $this->assertTrue($this->rule(3.14)->passes());
        $this->assertTrue($this->rule(-0.5)->passes());
    }

    public function test_accepts_decimal_string(): void
    {
        $this->assertTrue($this->rule('123')->passes());
        $this->assertTrue($this->rule('123.45')->passes());
        $this->assertTrue($this->rule('-7.5')->passes());
    }

    public function test_empty_is_skipped(): void
    {
        $this->assertTrue($this->rule(null)->passes());
        $this->assertTrue($this->rule('')->passes());
    }

    public function test_rejects_scientific_notation(): void
    {
        // is_numeric() accepts this, but typed user input rarely should.
        $this->assertFalse($this->rule('5e3')->passes());
        $this->assertFalse($this->rule('1.5E10')->passes());
    }

    public function test_rejects_leading_or_trailing_garbage(): void
    {
        $this->assertFalse($this->rule('123abc')->passes());
        $this->assertFalse($this->rule(' 123')->passes());
        $this->assertFalse($this->rule('123 ')->passes());
        $this->assertFalse($this->rule('+123')->passes());  // leading + not allowed
    }

    public function test_rejects_hex_and_octal_strings(): void
    {
        $this->assertFalse($this->rule('0x1A')->passes());
        $this->assertFalse($this->rule('0b1010')->passes());
    }

    public function test_rejects_arrays_and_objects(): void
    {
        $this->assertFalse($this->rule([1])->passes());
        $this->assertFalse($this->rule(new \stdClass())->passes());
    }
}
