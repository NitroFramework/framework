<?php

namespace Tests\Unit\Support;

use Nitro\Facades\Validator;
use Nitro\Support\Arr;
use Nitro\Support\Str;
use PHPUnit\Framework\TestCase;

/**
 * Str/Arr helper classes (Laravel parity) + the Validator::make()->fails()
 * auto-run fix.
 */
class StrArrTest extends TestCase
{
    public function test_str_case_conversions(): void
    {
        $this->assertSame('hello-world', Str::slug('Hello World!'));
        $this->assertSame('BlogPost', Str::studly('blog_post'));
        $this->assertSame('blogPost', Str::camel('blog_post'));
        $this->assertSame('blog_post', Str::snake('BlogPost'));
        $this->assertSame('blog-post', Str::kebab('BlogPost'));
    }

    public function test_str_predicates_and_limit(): void
    {
        $this->assertTrue(Str::contains('hello world', 'world'));
        $this->assertTrue(Str::startsWith('hello', 'he'));
        $this->assertTrue(Str::endsWith('hello', 'lo'));
        $this->assertSame('hel...', Str::limit('hello world', 3));
        $this->assertSame('hello world', Str::limit('hello world', 50));
    }

    public function test_str_after_before_finish_start(): void
    {
        $this->assertSame('world', Str::after('hello world', 'hello '));
        $this->assertSame('hello', Str::before('hello world', ' world'));
        $this->assertSame('a/', Str::finish('a', '/'));
        $this->assertSame('a/', Str::finish('a/', '/'));
        $this->assertSame('/a', Str::start('a', '/'));
        $this->assertSame('/a', Str::start('/a', '/'));
    }

    public function test_str_uuid_is_valid(): void
    {
        $uuid = Str::uuid();
        $this->assertTrue(Str::isUuid($uuid));
        $this->assertNotSame(Str::uuid(), Str::uuid());
    }

    public function test_arr_dot_access(): void
    {
        $data = ['mail' => ['from' => ['address' => 'a@b.c']]];
        $this->assertSame('a@b.c', Arr::get($data, 'mail.from.address'));
        $this->assertSame('x', Arr::get($data, 'mail.missing', 'x'));
        $this->assertTrue(Arr::has($data, 'mail.from.address'));
        $this->assertFalse(Arr::has($data, 'mail.from.name'));
    }

    public function test_arr_set_only_except_pluck(): void
    {
        $a = [];
        Arr::set($a, 'a.b.c', 1);
        $this->assertSame(1, $a['a']['b']['c']);

        $this->assertSame(['name' => 'x'], Arr::only(['name' => 'x', 'age' => 1], ['name']));
        $this->assertSame(['age' => 1], Arr::except(['name' => 'x', 'age' => 1], ['name']));

        $rows = [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']];
        $this->assertSame(['A', 'B'], Arr::pluck($rows, 'name'));
        $this->assertSame([1 => 'A', 2 => 'B'], Arr::pluck($rows, 'name', 'id'));
    }

    public function test_arr_first_last_flatten_wrap(): void
    {
        $this->assertSame(1, Arr::first([1, 2, 3]));
        $this->assertSame(3, Arr::last([1, 2, 3]));
        $this->assertSame(2, Arr::first([1, 2, 3], fn ($v) => $v > 1));
        $this->assertSame([1, 2, 3, 4], Arr::flatten([[1, 2], [3, [4]]]));
        $this->assertSame(['x'], Arr::wrap('x'));
        $this->assertSame([], Arr::wrap(null));
    }

    public function test_validator_make_fails_without_explicit_validate(): void
    {
        $invalid = Validator::make(['email' => 'nope'], ['email' => 'required|email']);
        $this->assertTrue($invalid->fails());

        $valid = Validator::make(['email' => 'a@b.c'], ['email' => 'required|email']);
        $this->assertFalse($valid->fails());
        $this->assertTrue($valid->passes());
    }
}
