<?php

namespace Tests\Unit\Foundation;

use Nitro\Foundation\MaintenanceMode;
use PHPUnit\Framework\TestCase;

/**
 * Taking the application down and bringing it back.
 */
class MaintenanceModeTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = sys_get_temp_dir() . '/nitro-down-' . getmypid() . '-' . uniqid();
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    private function mode(): MaintenanceMode
    {
        return new MaintenanceMode($this->file);
    }

    public function test_it_starts_up(): void
    {
        $this->assertFalse($this->mode()->active());
        $this->assertSame([], $this->mode()->data());
        $this->assertNull($this->mode()->retryAfter());
    }

    public function test_activating_takes_it_down(): void
    {
        $mode = $this->mode();

        $mode->activate();

        $this->assertTrue($mode->active());
    }

    public function test_deactivating_brings_it_back(): void
    {
        $mode = $this->mode();

        $mode->activate();
        $mode->deactivate();

        $this->assertFalse($mode->active());
    }

    public function test_deactivating_when_up_is_harmless(): void
    {
        $this->mode()->deactivate();

        $this->assertFalse($this->mode()->active());
    }

    public function test_the_payload_is_kept(): void
    {
        $mode = $this->mode();

        $mode->activate(['retry' => 60, 'message' => 'Back shortly']);

        $this->assertSame(60, $mode->retryAfter());
        $this->assertSame('Back shortly', $mode->data()['message']);
        $this->assertArrayHasKey('time', $mode->data());
    }

    public function test_a_secret_lets_somebody_through(): void
    {
        $mode = $this->mode();

        $mode->activate(['secret' => 'let-me-in']);

        $this->assertTrue($mode->bypassedBy('let-me-in'));
        $this->assertFalse($mode->bypassedBy('wrong'));
        $this->assertFalse($mode->bypassedBy(null));
    }

    /** With no secret configured, nothing gets through. */
    public function test_no_secret_means_no_bypass(): void
    {
        $mode = $this->mode();

        $mode->activate();

        $this->assertFalse($mode->bypassedBy(''));
        $this->assertFalse($mode->bypassedBy('anything'));
    }

    public function test_a_state_kept_on_disk_is_seen_by_another_instance(): void
    {
        $this->mode()->activate(['retry' => 30]);

        $this->assertTrue($this->mode()->active());
        $this->assertSame(30, $this->mode()->retryAfter());
    }
}
