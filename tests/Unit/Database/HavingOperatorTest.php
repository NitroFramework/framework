<?php

namespace Tests\Unit\Database;

use Nitro\Database\Connection;
use Nitro\Database\Query\Grammar\Grammar;
use Nitro\Database\Query\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * having() uses the same argument-count operator/value split as where(), so an
 * explicit operator is never mistaken for the value.
 */
class HavingOperatorTest extends TestCase
{
    private function builder(): QueryBuilder
    {
        return new QueryBuilder($this->createMock(Connection::class), new Grammar());
    }

    public function test_three_arg_having_keeps_operator(): void
    {
        $q = $this->builder()->from('orders')->groupBy('user_id')->having('total', '>', 100);

        $this->assertStringContainsString('HAVING `total` > ?', $q->toSql());
        $this->assertSame([100], $q->getBindings());
    }

    public function test_two_arg_having_defaults_to_equals(): void
    {
        // 2-arg form: the value moves into place and the operator defaults to
        // '='. (Value is bound as a string here because having()'s operator
        // slot is typed ?string, so the literal is coerced — harmless.)
        $q = $this->builder()->from('orders')->groupBy('user_id')->having('total', '100');

        $this->assertStringContainsString('HAVING `total` = ?', $q->toSql());
        $this->assertSame(['100'], $q->getBindings());
    }
}
