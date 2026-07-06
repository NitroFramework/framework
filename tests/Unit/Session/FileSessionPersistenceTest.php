<?php

namespace Tests\Unit\Session;

use Nitro\Session\NativeSession;
use Nitro\Session\SessionManager;
use Nitro\Session\Store;
use PHPUnit\Framework\TestCase;

/**
 * The file driver must persist across requests when the session id is carried
 * (which the SessionServiceProvider now does via a cookie). Two SessionManagers
 * over the same files dir simulate two requests.
 */
class FileSessionPersistenceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = dirname(__DIR__) . '/storage/tests_sessions';
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function config(): array
    {
        return ['driver' => 'file', 'cookie' => 'nitro_session', 'lifetime' => 120, 'files' => $this->dir];
    }

    public function test_file_session_persists_when_id_is_carried(): void
    {
        // Request 1: fresh session, store data, remember its id.
        $s1 = (new SessionManager($this->config()))->driver();
        $s1->start();
        $s1->put('cart', ['apple', 'pear']);
        $id = $s1->getId();
        $s1->save();

        $this->assertNotSame('', $id);

        // Request 2: a new Store seeded with the carried id reads the data back.
        $s2 = (new SessionManager($this->config()))->driver();
        $s2->setId($id);
        $s2->start();

        $this->assertSame(['apple', 'pear'], $s2->get('cart'));
    }

    public function test_file_driver_is_not_native(): void
    {
        // The provider only does manual cookie wiring for non-native drivers.
        $store = (new SessionManager($this->config()))->driver();
        $this->assertInstanceOf(Store::class, $store);
        $this->assertNotInstanceOf(NativeSession::class, $store);
    }
}
