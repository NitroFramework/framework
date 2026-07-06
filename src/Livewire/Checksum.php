<?php

namespace Nitro\Livewire;

use RuntimeException;

/**
 * Signs and verifies a component snapshot so the browser cannot tamper with the
 * server-held state between requests. The checksum is an HMAC over the snapshot's
 * data + memo, keyed by the application key.
 */
class Checksum
{
    public function __construct(private string $key) {}

    /** Generate the integrity hash for a snapshot's data + memo. */
    public function generate(array $data, array $memo): string
    {
        return hash_hmac('sha256', $this->canonical($data, $memo), $this->key);
    }

    /**
     * Verify a received snapshot's checksum, throwing if it doesn't match the
     * recomputed value (i.e. the data or memo was altered client-side).
     */
    public function verify(array $snapshot): void
    {
        $received = (string) ($snapshot['checksum'] ?? '');
        $expected = $this->generate($snapshot['data'] ?? [], $snapshot['memo'] ?? []);

        if (! hash_equals($expected, $received)) {
            throw new RuntimeException(
                'Livewire snapshot checksum mismatch — component state was tampered with.'
            );
        }
    }

    /** Deterministic serialization of the signed portion of the snapshot. */
    private function canonical(array $data, array $memo): string
    {
        return json_encode(
            ['data' => $data, 'memo' => $memo],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}
