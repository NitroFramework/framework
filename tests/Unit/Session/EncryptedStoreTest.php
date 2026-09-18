<?php

namespace Tests\Unit\Session;

use Nitro\Encryption\Encrypter;
use Nitro\Session\ArraySessionHandler;
use Nitro\Session\EncryptedStore;
use Nitro\Session\Store;
use PHPUnit\Framework\TestCase;

/**
 * A store whose payload is encrypted before it reaches the handler.
 */
class EncryptedStoreTest extends TestCase
{
    private function encrypter(string $key = 'a'): Encrypter
    {
        return new Encrypter(str_repeat($key, 32));
    }

    private function store(
        ArraySessionHandler $handler,
        ?Encrypter $encrypter = null,
        ?string $id = null,
    ): EncryptedStore {
        return new EncryptedStore('nitro_session', $handler, $encrypter ?? $this->encrypter(), $id);
    }

    public function test_a_session_round_trips(): void
    {
        $handler = new ArraySessionHandler();

        $session = $this->store($handler);
        $session->start();
        $session->put('user', 'ada');
        $session->save();

        $resumed = $this->store($handler, null, $session->getId());
        $resumed->start();

        $this->assertSame('ada', $resumed->get('user'));
    }

    /** The point of the class: what the handler holds must not be readable. */
    public function test_the_handler_never_sees_the_plaintext(): void
    {
        $handler = new ArraySessionHandler();

        $session = $this->store($handler);
        $session->start();
        $session->put('secret', 'hunter2');
        $session->save();

        $persisted = $handler->read($session->getId());

        $this->assertNotSame('', $persisted);
        $this->assertStringNotContainsString('hunter2', $persisted);
    }

    /** A plain Store, by contrast, writes the payload as-is. */
    public function test_a_plain_store_does_write_readable_data(): void
    {
        $handler = new ArraySessionHandler();

        $session = new Store('nitro_session', $handler);
        $session->start();
        $session->put('secret', 'hunter2');
        $session->save();

        $this->assertStringContainsString('hunter2', $handler->read($session->getId()));
    }

    /**
     * A rotated key must log the user out, not fail every request.
     */
    public function test_a_payload_that_cannot_be_decrypted_reads_as_an_empty_session(): void
    {
        $handler = new ArraySessionHandler();

        $session = $this->store($handler, $this->encrypter('a'));
        $session->start();
        $session->put('user', 'ada');
        $session->save();

        $rotated = $this->store($handler, $this->encrypter('b'), $session->getId());
        $rotated->start();

        $this->assertNull($rotated->get('user'));
    }

    public function test_the_encrypter_is_reachable(): void
    {
        $encrypter = $this->encrypter();

        $this->assertSame($encrypter, $this->store(new ArraySessionHandler(), $encrypter)->getEncrypter());
    }
}
