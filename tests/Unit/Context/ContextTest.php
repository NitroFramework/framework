<?php

namespace Tests\Unit\Context;

use Nitro\Context\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Context: data that travels with the request or job.
 */
class ContextTest extends TestCase
{
    public function test_it_holds_and_returns_values(): void
    {
        $context = new Repository();

        $context->add('trace_id', 'abc');

        $this->assertSame('abc', $context->get('trace_id'));
        $this->assertTrue($context->has('trace_id'));
        $this->assertFalse($context->has('nope'));
        $this->assertTrue($context->missing('nope'));
        $this->assertSame(['trace_id' => 'abc'], $context->all());
    }

    public function test_it_adds_several_at_once(): void
    {
        $context = (new Repository())->add(['a' => 1, 'b' => 2]);

        $this->assertSame(['a' => 1], $context->only(['a']));
    }

    public function test_add_if_does_not_overwrite(): void
    {
        $context = (new Repository())->add('k', 'first')->addIf('k', 'second');

        $this->assertSame('first', $context->get('k'));
    }

    public function test_pull_reads_and_removes(): void
    {
        $context = (new Repository())->add('once', 'value');

        $this->assertSame('value', $context->pull('once'));
        $this->assertFalse($context->has('once'));
    }

    public function test_a_default_is_returned_for_a_missing_key(): void
    {
        $this->assertSame('fallback', (new Repository())->get('absent', 'fallback'));
    }

    public function test_values_can_be_stacked(): void
    {
        $context = (new Repository())->push('breadcrumbs', 'one', 'two');

        $this->assertSame(['one', 'two'], $context->get('breadcrumbs'));
        $this->assertTrue($context->stackContains('breadcrumbs', 'two'));
        $this->assertFalse($context->stackContains('breadcrumbs', 'three'));
    }

    public function test_keys_can_be_forgotten(): void
    {
        $context = (new Repository())->add(['a' => 1, 'b' => 2, 'c' => 3]);

        $context->forget(['a', 'b']);

        $this->assertSame(['c' => 3], $context->all());
    }

    public function test_hidden_context_is_kept_apart(): void
    {
        $context = (new Repository())->add('shown', 1)->addHidden('secret', 2);

        $this->assertSame(['shown' => 1], $context->all());
        $this->assertSame(['secret' => 2], $context->allHidden());
        $this->assertSame(2, $context->getHidden('secret'));
        $this->assertTrue($context->hasHidden('secret'));
    }

    public function test_hidden_keys_can_be_forgotten(): void
    {
        $context = (new Repository())->addHidden('secret', 1);

        $context->forgetHidden('secret');

        $this->assertFalse($context->hasHidden('secret'));
    }

    public function test_scope_restores_what_was_there(): void
    {
        $context = (new Repository())->add('outer', 1);

        $result = $context->scope(['inner' => 2], function () use ($context): string {
            $this->assertSame(2, $context->get('inner'));

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertFalse($context->has('inner'));
        $this->assertSame(1, $context->get('outer'));
    }

    /** A throw inside the scope must not leave its keys behind. */
    public function test_scope_restores_after_an_exception(): void
    {
        $context = (new Repository())->add('outer', 1);

        try {
            $context->scope(['inner' => 2], function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse($context->has('inner'));
    }

    public function test_it_survives_dehydrating_and_hydrating(): void
    {
        $context = (new Repository())->add('trace', 'abc')->addHidden('token', 'xyz');

        $restored = (new Repository())->hydrate($context->dehydrate());

        $this->assertSame('abc', $restored->get('trace'));
        $this->assertSame('xyz', $restored->getHidden('token'));
    }

    public function test_hydrating_nothing_leaves_it_empty(): void
    {
        $this->assertTrue((new Repository())->hydrate(null)->isEmpty());
    }

    public function test_flush_empties_both_stores(): void
    {
        $context = (new Repository())->add('a', 1)->addHidden('b', 2);

        $this->assertFalse($context->isEmpty());

        $context->flush();

        $this->assertTrue($context->isEmpty());
    }
}
