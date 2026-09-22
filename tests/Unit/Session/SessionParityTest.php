<?php

namespace Tests\Unit\Session;

use Nitro\Session\ArraySessionHandler;
use Nitro\Session\DatabaseSessionHandler;
use Nitro\Session\SessionManager;
use PHPUnit\Framework\TestCase;
use SessionHandlerInterface;

/**
 * The two things the session layer could not do.
 *
 * A session row recorded only its payload, so an application had no way to
 * answer "where am I signed in?" — the question every security page asks. And
 * the driver list was a closed match, so storing sessions anywhere the
 * framework did not already know about meant editing the framework.
 */
class SessionParityTest extends TestCase
{
    /** A driver an application registered is built for it. */
    public function test_a_custom_driver_can_be_registered(): void
    {
        $handler = new ArraySessionHandler(120);

        $manager = (new SessionManager(['driver' => 'elsewhere', 'cookie' => 'nitro_session']))
            ->extend('elsewhere', static fn (array $config): SessionHandlerInterface => $handler);

        $this->assertSame($handler, $manager->driver()->getHandler());
    }

    /** Registration is also how a built-in driver is replaced. */
    public function test_a_registered_driver_replaces_a_built_in_one(): void
    {
        $handler = new ArraySessionHandler(120);

        $manager = (new SessionManager(['driver' => 'array', 'cookie' => 'nitro_session']))
            ->extend('array', static fn (array $config): SessionHandlerInterface => $handler);

        $this->assertSame($handler, $manager->driver()->getHandler());
    }

    /** The config reaches the driver, so it can read its own settings. */
    public function test_a_custom_driver_is_given_the_session_config(): void
    {
        $seen = null;

        (new SessionManager(['driver' => 'elsewhere', 'cookie' => 'nitro_session', 'table' => 'my_sessions']))
            ->extend('elsewhere', static function (array $config) use (&$seen): SessionHandlerInterface {
                $seen = $config;

                return new ArraySessionHandler(120);
            })
            ->driver();

        $this->assertSame('my_sessions', $seen['table']);
    }

    // --- what a session row records -----------------------------------------

    /**
     * Who and where are written alongside the payload.
     *
     * Read back through the protected builder rather than by writing to a
     * database, because what is being asserted is the shape of the row, and a
     * connection would only stand between the test and that.
     */
    public function test_a_session_row_records_who_and_where(): void
    {
        $handler = new class('sessions', 120,
            static fn (): int => 42,
            static fn (): array => ['ip_address' => '203.0.113.7', 'user_agent' => 'Mozilla/5.0']
        ) extends DatabaseSessionHandler {
            /** @return array<string, mixed> */
            public function payloadFor(string $data): array
            {
                return $this->defaultPayload($data);
            }
        };

        $row = $handler->payloadFor('serialized');

        $this->assertSame(42, $row['user_id']);
        $this->assertSame('203.0.113.7', $row['ip_address']);
        $this->assertSame('Mozilla/5.0', $row['user_agent']);
        $this->assertSame(base64_encode('serialized'), $row['payload']);
        $this->assertIsInt($row['last_activity']);
    }

    /**
     * A guest's session records no user, but still records where it came from.
     *
     * That is the case a security page needs most: a session with no user is
     * how a visit looks before somebody signs in.
     */
    public function test_a_guest_session_still_records_its_origin(): void
    {
        $handler = new class('sessions', 120,
            static fn (): ?int => null,
            static fn (): array => ['ip_address' => '198.51.100.4', 'user_agent' => 'curl/8']
        ) extends DatabaseSessionHandler {
            /** @return array<string, mixed> */
            public function payloadFor(string $data): array
            {
                return $this->defaultPayload($data);
            }
        };

        $row = $handler->payloadFor('x');

        $this->assertNull($row['user_id']);
        $this->assertSame('198.51.100.4', $row['ip_address']);
    }

    /**
     * Without the layers that supply them, those columns are simply not written.
     *
     * A console command has no request and may have no auth; the session still
     * has to work there, and writing nulls into columns an application may not
     * have added would fail the insert instead.
     */
    public function test_the_columns_are_omitted_when_nothing_supplies_them(): void
    {
        $handler = new class('sessions', 120) extends DatabaseSessionHandler {
            /** @return array<string, mixed> */
            public function payloadFor(string $data): array
            {
                return $this->defaultPayload($data);
            }
        };

        $row = $handler->payloadFor('x');

        $this->assertArrayNotHasKey('user_id', $row);
        $this->assertArrayNotHasKey('ip_address', $row);
        $this->assertArrayHasKey('payload', $row);
    }
}
