<?php

namespace Tests\Unit\Validation;

use Nitro\Validation\Rules\Unique;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class UniqueRuleTest extends TestCase
{
    protected function rule(mixed $value, array $params = [], array $data = []): Unique
    {
        $rule = new Unique();
        $rule->setAttribute('field');
        $rule->setValue($value);
        $rule->setData($data);

        // parameters is protected; inject directly for tests.
        $prop = new ReflectionProperty(Unique::class, 'parameters');
        $prop->setValue($rule, $params);

        return $rule;
    }

    public function test_empty_value_is_skipped(): void
    {
        $this->assertTrue($this->rule('', ['users', 'email'])->passes());
        $this->assertTrue($this->rule(null, ['users', 'email'])->passes());
    }

    public function test_missing_table_or_column_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rule('x', [])->passes();
    }

    public function test_injection_in_table_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rule('x', ['users; DROP TABLE users; --', 'email'])->passes();
    }

    public function test_injection_in_column_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rule('x', ['users', 'email) OR 1=1 --'])->passes();
    }

    public function test_missing_model_class_fails_closed(): void
    {
        // No model exists for table "definitely_not_a_real_table_xyz".
        $this->expectException(\RuntimeException::class);
        $this->rule('x', ['definitely_not_a_real_table_xyz', 'col'])->passes();
    }
}
