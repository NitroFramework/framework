<?php

namespace Tests\Unit\Livewire;

use Nitro\Livewire\Checksum;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Security tests for the Livewire snapshot checksum — the integrity half of
 * Livewire's defence (the CSRF token is the other half, see LivewireCsrfTest).
 *
 * The checksum is an HMAC-SHA256 over the snapshot's data+memo keyed by the app
 * key, so a client cannot forge or tamper with the server-held state it round-
 * trips. Verification is constant-time (hash_equals).
 */
class ChecksumSecurityTest extends TestCase
{
    private function signer(string $key = 'test-app-key'): Checksum
    {
        return new Checksum($key);
    }

    private function snapshot(Checksum $signer, array $data, array $memo): array
    {
        return ['data' => $data, 'memo' => $memo, 'checksum' => $signer->generate($data, $memo)];
    }

    public function test_untampered_snapshot_verifies(): void
    {
        $signer = $this->signer();
        $snapshot = $this->snapshot($signer, ['count' => 1], ['id' => 'abc', 'name' => 'counter']);

        $signer->verify($snapshot);
        $this->addToAssertionCount(1); // no exception == pass
    }

    public function test_tampered_data_is_rejected(): void
    {
        $signer = $this->signer();
        $snapshot = $this->snapshot($signer, ['count' => 1], ['id' => 'abc', 'name' => 'counter']);

        // Attacker bumps a protected value but keeps the original checksum.
        $snapshot['data']['count'] = 999999;

        $this->expectException(RuntimeException::class);
        $signer->verify($snapshot);
    }

    public function test_tampered_memo_is_rejected(): void
    {
        $signer = $this->signer();
        $snapshot = $this->snapshot($signer, ['count' => 1], ['id' => 'abc', 'name' => 'counter']);

        // Swapping the component identity must invalidate the signature.
        $snapshot['memo']['name'] = 'admin-panel';

        $this->expectException(RuntimeException::class);
        $signer->verify($snapshot);
    }

    public function test_missing_checksum_is_rejected(): void
    {
        $signer = $this->signer();

        $this->expectException(RuntimeException::class);
        $signer->verify(['data' => ['count' => 1], 'memo' => ['id' => 'abc']]);
    }

    public function test_empty_checksum_is_rejected(): void
    {
        $signer = $this->signer();

        $this->expectException(RuntimeException::class);
        $signer->verify(['data' => ['count' => 1], 'memo' => [], 'checksum' => '']);
    }

    public function test_checksum_is_keyed_by_app_key(): void
    {
        // A snapshot signed with one app key must NOT verify under another —
        // an attacker without the server key cannot forge a valid checksum.
        $data = ['count' => 1];
        $memo = ['id' => 'abc', 'name' => 'counter'];

        $snapshotFromAttackerKey = $this->snapshot($this->signer('attacker-key'), $data, $memo);

        $this->expectException(RuntimeException::class);
        $this->signer('server-key')->verify($snapshotFromAttackerKey);
    }

    public function test_generate_is_deterministic_for_same_input(): void
    {
        $signer = $this->signer();

        $this->assertSame(
            $signer->generate(['a' => 1], ['id' => 'x']),
            $signer->generate(['a' => 1], ['id' => 'x']),
        );
    }
}
