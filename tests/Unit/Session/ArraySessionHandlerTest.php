<?php

namespace Tests\Unit\Session;

use Nitro\Session\Handlers\ArraySessionHandler;
use PHPUnit\Framework\TestCase;

class ArraySessionHandlerTest extends TestCase
{
    public function test_read_missing_returns_empty_string(): void
    {
        $h = new ArraySessionHandler();
        $this->assertSame('', $h->read('nope'));
    }

    public function test_write_then_read(): void
    {
        $h = new ArraySessionHandler();
        $this->assertTrue($h->write('id1', 'payload'));
        $this->assertSame('payload', $h->read('id1'));
    }

    public function test_destroy_removes_entry(): void
    {
        $h = new ArraySessionHandler();
        $h->write('id1', 'payload');
        $this->assertTrue($h->destroy('id1'));
        $this->assertSame('', $h->read('id1'));
    }

    public function test_open_and_close_succeed(): void
    {
        $h = new ArraySessionHandler();
        $this->assertTrue($h->open('', 'nitro'));
        $this->assertTrue($h->close());
    }

    public function test_gc_returns_count_removed(): void
    {
        $h = new ArraySessionHandler();
        $h->write('a', 'x');
        $h->write('b', 'y');
        // gc with a negative max-lifetime makes every entry "older than cutoff".
        $removed = $h->gc(-1);
        $this->assertSame(2, $removed);
        $this->assertSame('', $h->read('a'));
    }
}
