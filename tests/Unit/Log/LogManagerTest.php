<?php

namespace Tests\Unit\Log;

use InvalidArgumentException;
use Nitro\Log\Handlers\ErrorLogHandler;
use Nitro\Log\Handlers\NullHandler;
use Nitro\Log\Handlers\StackHandler;
use Nitro\Log\Handlers\StreamHandler;
use Nitro\Log\LogManager;
use PHPUnit\Framework\TestCase;

/**
 * Which channel a name resolves to, and what happens when it resolves to none.
 *
 * A platform that assumes another framework will inject a channel this
 * application has never heard of. That used to fall through to a file on an
 * ephemeral disk, so the log looked empty and nothing said why.
 */
class LogManagerTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . '/nitro_log_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tmp);

        parent::tearDown();
    }

    private function manager(array $config = []): LogManager
    {
        return new LogManager($config + [
            'default'  => 'single',
            'channels' => [
                'single'   => ['driver' => 'single', 'path' => $this->tmp . '/nitro.log'],
                'stderr'   => ['driver' => 'stream', 'stream' => 'php://stderr'],
                'errorlog' => ['driver' => 'errorlog'],
                'null'     => ['driver' => 'null'],
                'stack'    => ['driver' => 'stack', 'channels' => ['single', 'stderr']],
            ],
        ]);
    }

    // ─── The bug this closes ──────────────────────────────────

    /** An unknown channel must say so, not quietly write somewhere else. */
    public function test_an_unconfigured_channel_raises(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Log [laravel-cloud-socket] is not defined');

        $this->manager()->channel('laravel-cloud-socket');
    }

    /** The message has to name what IS available, or it cannot be acted on. */
    public function test_the_failure_lists_the_channels_that_exist(): void
    {
        try {
            $this->manager()->channel('nope');
            $this->fail('expected an InvalidArgumentException');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('single', $exception->getMessage());
            $this->assertStringContainsString('stderr', $exception->getMessage());
        }
    }

    public function test_an_unknown_driver_raises(): void
    {
        $manager = $this->manager(['channels' => [
            'weird' => ['driver' => 'telepathy'],
        ]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Log driver [telepathy] is not supported');

        $manager->channel('weird');
    }

    public function test_a_channel_naming_no_driver_raises(): void
    {
        $manager = $this->manager(['channels' => ['bare' => ['path' => '/tmp/x.log']]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('names no driver');

        $manager->channel('bare');
    }

    /** A stack that contains itself would recurse until the process died. */
    public function test_a_self_referential_stack_raises(): void
    {
        $manager = $this->manager(['channels' => [
            'loop' => ['driver' => 'stack', 'channels' => ['loop']],
        ]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contains itself');

        $manager->channel('loop');
    }

    // ─── Resolution ───────────────────────────────────────────

    public function test_each_driver_builds_its_handler(): void
    {
        $manager = $this->manager();

        $this->assertInstanceOf(StreamHandler::class, $manager->channel('single')->getHandler());
        $this->assertInstanceOf(StreamHandler::class, $manager->channel('stderr')->getHandler());
        $this->assertInstanceOf(ErrorLogHandler::class, $manager->channel('errorlog')->getHandler());
        $this->assertInstanceOf(NullHandler::class, $manager->channel('null')->getHandler());
        $this->assertInstanceOf(StackHandler::class, $manager->channel('stack')->getHandler());
    }

    public function test_a_channel_is_built_once(): void
    {
        $manager = $this->manager();

        $this->assertSame($manager->channel('single'), $manager->channel('single'));
    }

    public function test_forgetting_a_channel_rebuilds_it(): void
    {
        $manager = $this->manager();
        $first = $manager->channel('single');

        $manager->forgetChannel('single');

        $this->assertNotSame($first, $manager->channel('single'));
    }

    public function test_calls_on_the_manager_go_to_the_default_channel(): void
    {
        $this->manager()->info('through the manager');

        $this->assertStringContainsString(
            'through the manager',
            file_get_contents($this->tmp . '/nitro.log')
        );
    }

    // ─── Writing ──────────────────────────────────────────────

    public function test_a_line_carries_its_level_and_context(): void
    {
        $this->manager()->channel('single')->error('it broke', ['order' => 12]);

        $written = file_get_contents($this->tmp . '/nitro.log');

        $this->assertStringContainsString('ERROR: it broke', $written);
        $this->assertStringContainsString('{"order":12}', $written);
    }

    public function test_a_level_below_the_channel_floor_is_dropped(): void
    {
        $manager = $this->manager(['channels' => [
            'warnings' => ['driver' => 'single', 'path' => $this->tmp . '/warn.log', 'level' => 'warning'],
        ]]);

        $manager->channel('warnings')->debug('chatter');
        $manager->channel('warnings')->error('serious');

        $written = file_get_contents($this->tmp . '/warn.log');

        $this->assertStringNotContainsString('chatter', $written);
        $this->assertStringContainsString('serious', $written);
    }

    public function test_a_stack_writes_to_every_channel_in_it(): void
    {
        $manager = $this->manager(['channels' => [
            'a'    => ['driver' => 'single', 'path' => $this->tmp . '/a.log'],
            'b'    => ['driver' => 'single', 'path' => $this->tmp . '/b.log'],
            'both' => ['driver' => 'stack', 'channels' => ['a', 'b']],
        ]]);

        $manager->channel('both')->info('to both');

        $this->assertStringContainsString('to both', file_get_contents($this->tmp . '/a.log'));
        $this->assertStringContainsString('to both', file_get_contents($this->tmp . '/b.log'));
    }

    public function test_the_null_channel_keeps_nothing(): void
    {
        $manager = $this->manager();
        $manager->channel('null')->error('vanishes');

        $this->assertFalse(is_file($this->tmp . '/null.log'));
    }

    // ─── Extension ────────────────────────────────────────────

    public function test_a_custom_driver_can_be_registered(): void
    {
        $manager = $this->manager(['channels' => [
            'custom' => ['driver' => 'mine'],
        ]]);

        $manager->extend('mine', fn (array $config) => new NullHandler());

        $this->assertInstanceOf(NullHandler::class, $manager->channel('custom')->getHandler());
    }

    public function test_an_ad_hoc_stack_can_be_built_from_names(): void
    {
        $manager = $this->manager();

        $this->assertInstanceOf(
            StackHandler::class,
            $manager->stack(['single', 'null'])->getHandler()
        );
    }
}
