<?php

namespace Tests\Unit\Htmx;

use Nitro\Exceptions\HttpException;
use Nitro\Htmx\Support\HxObfuscator;
use PHPUnit\Framework\TestCase;

/**
 * HxObfuscator maps component/action names to short, stable HMAC hashes so
 * the wire never exposes real class or method names, and reverses known
 * hashes back on the request hot-path. When disabled it is a transparent
 * passthrough. Unknown hashes are a 404 — you can only reach what was
 * pre-registered (allowlist + filesystem discovery).
 */
class HxObfuscatorTest extends TestCase
{
    private const KEY = 'app-key-for-tests';
    private const NS  = 'App\\Htmx\\Components';

    private function enabled(array $allowed = []): HxObfuscator
    {
        return new HxObfuscator(
            enabled: true,
            appKey: self::KEY,
            componentNamespace: self::NS,
            allowedComponents: $allowed,
        );
    }

    // ── disabled: transparent passthrough ────────────────────────────────

    public function test_disabled_obfuscator_is_a_passthrough(): void
    {
        $o = new HxObfuscator(false, self::KEY, self::NS);

        $this->assertSame('counter', $o->obfuscate('counter'));
        $this->assertSame('increment', $o->obfuscateAction('increment', 'counter'));
        $this->assertSame('counter', $o->reverseLookup('counter'));
        $this->assertSame('increment', $o->reverseActionLookup('counter', 'increment'));
    }

    // ── enabled: hashing ─────────────────────────────────────────────────

    public function test_obfuscate_is_a_stable_16_char_hash(): void
    {
        $o = $this->enabled();
        $hash = $o->obfuscate('counter');

        $this->assertSame(16, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $hash);
        $this->assertSame($hash, $o->obfuscate('counter'), 'hashing must be deterministic');
        $this->assertNotSame('counter', $hash);
    }

    public function test_different_names_and_keys_produce_different_hashes(): void
    {
        $a = $this->enabled();
        $this->assertNotSame($a->obfuscate('counter'), $a->obfuscate('userCard'));

        $b = new HxObfuscator(true, 'a-totally-different-key', self::NS);
        $this->assertNotSame($a->obfuscate('counter'), $b->obfuscate('counter'),
            'the app key must salt the hash');
    }

    public function test_action_hash_is_scoped_to_its_component(): void
    {
        $o = $this->enabled();
        // Same action name on two components must not collide.
        $this->assertNotSame(
            $o->obfuscateAction('save', 'invoiceForm'),
            $o->obfuscateAction('save', 'profileForm'),
        );
    }

    // ── enabled: reverse lookup ──────────────────────────────────────────

    public function test_reverse_lookup_resolves_allowlisted_components(): void
    {
        $o = $this->enabled(['counter', 'userCard']);

        $this->assertSame('counter', $o->reverseLookup($o->obfuscate('counter')));
        $this->assertSame('userCard', $o->reverseLookup($o->obfuscate('userCard')));
    }

    public function test_reverse_lookup_of_an_unknown_hash_is_a_404(): void
    {
        $o = $this->enabled(['counter']);

        try {
            $o->reverseLookup('deadbeefdeadbeef');
            $this->fail('expected an HttpException for an unregistered hash');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }
}
