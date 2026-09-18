<?php

namespace Tests\Unit\Log;

use Nitro\Log\Handlers\DailyHandler;
use Nitro\Log\Handlers\StreamHandler;
use PHPUnit\Framework\TestCase;

/**
 * Writing to a file, and writing to a stream.
 *
 * The two differ in more than their destination: a stream has no directory to
 * create, no size to rotate, and no support for an exclusive lock.
 */
class StreamHandlerTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . '/nitro_stream_' . bin2hex(random_bytes(4));
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

    public function test_a_line_is_appended_to_a_file(): void
    {
        $path = $this->tmp . '/app.log';
        $handler = new StreamHandler($path);

        $handler->write('info', 'first');
        $handler->write('info', 'second');

        $written = file_get_contents($path);

        $this->assertStringContainsString('INFO: first', $written);
        $this->assertStringContainsString('INFO: second', $written);
    }

    /**
     * A stream does not support LOCK_EX, and asking for one raises a warning
     * on every single line written.
     */
    public function test_writing_to_a_stream_does_not_warn(): void
    {
        $handler = new StreamHandler('php://memory');

        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $handler->write('info', 'to a stream');
        } finally {
            restore_error_handler();
        }

        $this->assertTrue(true);
    }

    public function test_a_missing_directory_is_created(): void
    {
        $path = $this->tmp . '/nested/deeper/app.log';

        (new StreamHandler($path))->write('info', 'made the directory');

        $this->assertFileExists($path);

        @unlink($path);
        @rmdir(dirname($path));
        @rmdir(dirname($path, 2));
    }

    public function test_a_file_is_rotated_once_it_passes_the_threshold(): void
    {
        $path = $this->tmp . '/rotate.log';
        $handler = new StreamHandler($path, 200);

        foreach (range(1, 12) as $i) {
            $handler->write('info', "line {$i} padded out to make the file grow");
        }

        $this->assertFileExists($path . '.1');
        $this->assertLessThan(400, filesize($path));
    }

    public function test_rotation_is_off_when_no_threshold_is_set(): void
    {
        $path = $this->tmp . '/norotate.log';
        $handler = new StreamHandler($path, 0);

        foreach (range(1, 20) as $i) {
            $handler->write('info', "line {$i}");
        }

        $this->assertFileDoesNotExist($path . '.1');
    }

    // ─── Daily ────────────────────────────────────────────

    public function test_the_daily_handler_names_its_file_for_today(): void
    {
        (new DailyHandler($this->tmp . '/app.log'))->write('info', 'today');

        $expected = $this->tmp . '/app-' . date('Y-m-d') . '.log';

        $this->assertFileExists($expected);
        $this->assertStringContainsString('today', file_get_contents($expected));
    }

    public function test_the_daily_handler_prunes_past_its_retention(): void
    {
        foreach (['2026-09-10', '2026-09-11', '2026-09-12'] as $day) {
            file_put_contents($this->tmp . '/app-' . $day . '.log', 'old');
        }

        (new DailyHandler($this->tmp . '/app.log', 2))->write('info', 'today');

        $remaining = glob($this->tmp . '/app-*.log') ?: [];

        $this->assertLessThanOrEqual(2, count($remaining));
    }
}
