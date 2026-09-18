<?php

namespace Tests\Unit\Testing;

use Nitro\Testing\ParallelTesting;
use PHPUnit\Framework\TestCase;

/**
 * Hooks for a suite split across several processes.
 */
class ParallelTestingTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('TEST_TOKEN');

        parent::tearDown();
    }

    public function test_hooks_fire_in_the_order_registered(): void
    {
        $ran = [];

        $parallel = new ParallelTesting();

        $parallel->setUpProcess(function () use (&$ran): void {
            $ran[] = 'process';
        });

        $parallel->setUpTestCase(function () use (&$ran): void {
            $ran[] = 'case';
        });

        $parallel->callHook('setUpProcess');
        $parallel->callHook('setUpTestCase');

        $this->assertSame(['process', 'case'], $ran);
    }

    public function test_every_hook_is_available(): void
    {
        $ran = [];

        $parallel = new ParallelTesting();

        foreach (['setUpProcess', 'setUpTestCase', 'setUpTestDatabase', 'tearDownTestCase', 'tearDownProcess'] as $hook) {
            $parallel->{$hook}(function () use (&$ran, $hook): void {
                $ran[] = $hook;
            });
        }

        foreach (['setUpProcess', 'setUpTestCase', 'setUpTestDatabase', 'tearDownTestCase', 'tearDownProcess'] as $hook) {
            $parallel->callHook($hook);
        }

        $this->assertCount(5, $ran);
    }

    public function test_arguments_reach_the_callback(): void
    {
        $seen = null;

        $parallel = new ParallelTesting();

        $parallel->setUpTestDatabase(function (string $database) use (&$seen): void {
            $seen = $database;
        });

        $parallel->callHook('setUpTestDatabase', 'nitro_test_2');

        $this->assertSame('nitro_test_2', $seen);
    }

    public function test_an_unknown_hook_is_harmless(): void
    {
        (new ParallelTesting())->callHook('nothingRegistered');

        $this->addToAssertionCount(1);
    }

    public function test_names_are_left_alone_outside_a_parallel_run(): void
    {
        $parallel = new ParallelTesting();

        $this->assertFalse($parallel->inParallel());
        $this->assertFalse($parallel->token());
        $this->assertSame('nitro', $parallel->tokenise('nitro'));
    }

    public function test_names_carry_the_token_during_a_parallel_run(): void
    {
        putenv('TEST_TOKEN=3');

        $parallel = new ParallelTesting();

        $this->assertTrue($parallel->inParallel());
        $this->assertSame('3', $parallel->token());
        $this->assertSame('nitro_test_3', $parallel->tokenise('nitro'));
    }

    public function test_flush_forgets_the_callbacks(): void
    {
        $ran = 0;

        $parallel = new ParallelTesting();

        $parallel->setUpProcess(function () use (&$ran): void {
            $ran++;
        });

        $parallel->flush();
        $parallel->callHook('setUpProcess');

        $this->assertSame(0, $ran);
    }
}
