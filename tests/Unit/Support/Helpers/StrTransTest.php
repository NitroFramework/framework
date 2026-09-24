<?php

namespace Tests\Unit\Support\Helpers;

use Nitro\Container\Container;
use Nitro\Support\Str;
use Nitro\Support\Stringable;
use Nitro\Translation\ArrayLoader;
use Nitro\Translation\Translator;
use PHPUnit\Framework\TestCase;

/**
 * Str::trans() hands the translated line straight on as a Stringable.
 *
 * The point is the chain: a translated line usually needs something doing to
 * it — limiting, slugging, title casing — and going through the translator
 * and then back through Str loses the fluent form.
 */
class StrTransTest extends TestCase
{
    protected function setUp(): void
    {
        Container::setInstance(new Container());

        $loader = (new ArrayLoader())->addMessages('en', 'messages', [
            'welcome' => 'welcome back, :name',
            'heading' => 'the tale of two cats',
        ]);

        Container::getInstance()->instance('translator', new Translator($loader, 'en', 'en'));
    }

    protected function tearDown(): void
    {
        Container::setInstance(new Container());

        parent::tearDown();
    }

    public function test_it_returns_the_translated_line(): void
    {
        $line = Str::trans('messages.welcome', ['name' => 'Ada']);

        $this->assertInstanceOf(Stringable::class, $line);
        $this->assertSame('welcome back, Ada', (string) $line);
    }

    /** The reason it returns a Stringable rather than a string. */
    public function test_the_result_can_be_carried_on_with(): void
    {
        $this->assertSame(
            'The Tale of Two Cats',
            (string) Str::trans('messages.heading')->apa(),
        );
    }

    public function test_a_missing_key_comes_back_as_itself(): void
    {
        $this->assertSame('messages.absent', (string) Str::trans('messages.absent'));
    }
}
