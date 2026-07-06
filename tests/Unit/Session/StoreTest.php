<?php

namespace Tests\Unit\Session;

use Nitro\Session\Handlers\ArraySessionHandler;
use Nitro\Session\Store;
use PHPUnit\Framework\TestCase;

class StoreTest extends TestCase
{
    private function store(?ArraySessionHandler $handler = null, ?string $id = null): Store
    {
        return new Store('nitro_session', $handler ?? new ArraySessionHandler(), $id);
    }

    // ─── Basic read/write ─────────────────────────────────────────────────

    public function test_put_and_get(): void
    {
        $s = $this->store();
        $s->put('name', 'Zeeshan');
        $this->assertSame('Zeeshan', $s->get('name'));
        $this->assertNull($s->get('missing'));
        $this->assertSame('fallback', $s->get('missing', 'fallback'));
    }

    public function test_put_array_of_pairs(): void
    {
        $s = $this->store();
        $s->put(['a' => 1, 'b' => 2]);
        $this->assertSame(1, $s->get('a'));
        $this->assertSame(2, $s->get('b'));
    }

    public function test_has_and_exists(): void
    {
        $s = $this->store();
        $s->put('nullable', null);
        $s->put('set', 'x');
        $this->assertTrue($s->exists('nullable'));  // present but null
        $this->assertFalse($s->has('nullable'));     // present-but-null is not "has"
        $this->assertTrue($s->has('set'));
        $this->assertFalse($s->exists('ghost'));
    }

    public function test_pull_returns_and_forgets(): void
    {
        $s = $this->store();
        $s->put('one_time', 'token');
        $this->assertSame('token', $s->pull('one_time'));
        $this->assertFalse($s->exists('one_time'));
    }

    public function test_push_onto_array(): void
    {
        $s = $this->store();
        $s->push('list', 'a');
        $s->push('list', 'b');
        $this->assertSame(['a', 'b'], $s->get('list'));
    }

    public function test_remember(): void
    {
        $s = $this->store();
        $calls = 0;
        $make = function () use (&$calls) { $calls++; return 'computed'; };
        $this->assertSame('computed', $s->remember('k', $make));
        $this->assertSame('computed', $s->remember('k', $make));
        $this->assertSame(1, $calls, 'callback runs only once');
    }

    public function test_increment_decrement(): void
    {
        $s = $this->store();
        $this->assertSame(1, $s->increment('hits'));
        $this->assertSame(3, $s->increment('hits', 2));
        $this->assertSame(2, $s->decrement('hits'));
    }

    public function test_forget_and_flush(): void
    {
        $s = $this->store();
        $s->put(['a' => 1, 'b' => 2]);
        $s->forget('a');
        $this->assertFalse($s->exists('a'));
        $this->assertTrue($s->exists('b'));
        $s->flush();
        $this->assertSame([], $s->all());
    }

    // ─── Dot notation ─────────────────────────────────────────────────────

    public function test_dot_notation_get_set_forget(): void
    {
        $s = $this->store();
        $s->put('user.profile.name', 'Ali');
        $this->assertSame('Ali', $s->get('user.profile.name'));
        $this->assertSame(['profile' => ['name' => 'Ali']], $s->get('user'));
        $this->assertTrue($s->has('user.profile.name'));
        $s->forget('user.profile.name');
        $this->assertFalse($s->has('user.profile.name'));
        $this->assertTrue($s->exists('user.profile')); // parent remains
    }

    // ─── Flash lifecycle ──────────────────────────────────────────────────

    public function test_flash_available_next_request_then_gone(): void
    {
        $handler = new ArraySessionHandler();

        // Request 1: flash a value, then persist.
        $r1 = $this->store($handler);
        $r1->start();
        $id = $r1->getId();
        $r1->flash('status', 'Saved!');
        $this->assertSame('Saved!', $r1->get('status'));
        $r1->save();

        // Request 2: same session — flash is still readable, then ages out on save.
        $r2 = $this->store($handler, $id);
        $r2->start();
        $this->assertSame('Saved!', $r2->get('status'), 'flash survives into the next request');
        $r2->save();

        // Request 3: flash is gone.
        $r3 = $this->store($handler, $id);
        $r3->start();
        $this->assertNull($r3->get('status'), 'flash is cleared after one request');
    }

    public function test_keep_extends_flash_one_more_request(): void
    {
        $handler = new ArraySessionHandler();

        $r1 = $this->store($handler);
        $r1->start();
        $id = $r1->getId();
        $r1->flash('note', 'keepme');
        $r1->save();

        $r2 = $this->store($handler, $id);
        $r2->start();
        $this->assertSame('keepme', $r2->get('note'));
        $r2->keep('note');          // keep it alive for another request
        $r2->save();

        $r3 = $this->store($handler, $id);
        $r3->start();
        $this->assertSame('keepme', $r3->get('note'), 'kept flash survives an extra request');
    }

    public function test_reflash_keeps_all_flash_data(): void
    {
        $handler = new ArraySessionHandler();

        $r1 = $this->store($handler);
        $r1->start();
        $id = $r1->getId();
        $r1->flash('a', 1);
        $r1->flash('b', 2);
        $r1->save();

        $r2 = $this->store($handler, $id);
        $r2->start();
        $r2->reflash();
        $r2->save();

        $r3 = $this->store($handler, $id);
        $r3->start();
        $this->assertSame(1, $r3->get('a'));
        $this->assertSame(2, $r3->get('b'));
    }

    // ─── Token + identity ─────────────────────────────────────────────────

    public function test_start_creates_csrf_token(): void
    {
        $s = $this->store();
        $s->start();
        $this->assertNotNull($s->token());
        $this->assertSame(40, strlen($s->token()));
    }

    public function test_regenerate_token_changes_it(): void
    {
        $s = $this->store();
        $s->start();
        $first = $s->token();
        $s->regenerateToken();
        $this->assertNotSame($first, $s->token());
    }

    public function test_session_id_is_40_char_alnum(): void
    {
        $s = $this->store();
        $this->assertSame(40, strlen($s->getId()));
        $this->assertTrue(ctype_alnum($s->getId()));
    }

    public function test_invalid_id_is_replaced_with_generated_one(): void
    {
        $s = $this->store(null, 'bad id with spaces');
        $this->assertNotSame('bad id with spaces', $s->getId());
        $this->assertSame(40, strlen($s->getId()));
    }

    public function test_regenerate_changes_id_and_token_but_keeps_data(): void
    {
        $s = $this->store();
        $s->start();
        $s->put('keep', 'value');
        $oldId = $s->getId();
        $oldToken = $s->token();

        $this->assertTrue($s->regenerate());
        $this->assertNotSame($oldId, $s->getId());
        $this->assertNotSame($oldToken, $s->token());
        $this->assertSame('value', $s->get('keep'), 'data survives regeneration');
    }

    public function test_invalidate_clears_data_and_changes_id(): void
    {
        $s = $this->store();
        $s->start();
        $s->put('secret', 'x');
        $oldId = $s->getId();

        $this->assertTrue($s->invalidate());
        $this->assertFalse($s->exists('secret'));
        $this->assertNotSame($oldId, $s->getId());
    }

    // ─── Persistence round-trip ───────────────────────────────────────────

    public function test_data_persists_across_store_instances_via_handler(): void
    {
        $handler = new ArraySessionHandler();

        $a = $this->store($handler);
        $a->start();
        $id = $a->getId();
        $a->put('user_id', 42);
        $a->save();

        $b = $this->store($handler, $id);
        $b->start();
        $this->assertSame(42, $b->get('user_id'));
    }
}
