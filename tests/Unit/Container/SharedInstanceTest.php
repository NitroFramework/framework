<?php

namespace Tests\Unit\Container;

use Nitro\Container\Container;
use Nitro\Foundation\Application;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Container::getInstance() must not invent a container.
 *
 * It used to build an empty one when none had been established, so calling
 * app() or a facade before the application booted returned a blank container
 * rather than failing. Nothing was wrong at that moment — the error surfaced
 * later, somewhere unrelated, as "service not found", and the actual mistake
 * was several frames and one misleading message away.
 *
 * Establishing the shared container is the application's job. Everything else
 * asks for one that already exists.
 */
class SharedInstanceTest extends TestCase
{
    private ?Container $previous = null;

    protected function setUp(): void
    {
        /*
         * These tests take the shared container apart, so the one the rest of
         * the suite is using is put back in tearDown whatever happens here.
         */
        $this->previous = Container::hasInstance() ? Container::getInstance() : null;
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
    }

    public function test_asking_for_a_container_that_was_never_established_fails(): void
    {
        Container::reset();

        $this->expectException(RuntimeException::class);

        Container::getInstance();
    }

    /** The message has to name the likely cause, or it is just a different confusing error. */
    public function test_the_failure_says_what_probably_went_wrong(): void
    {
        Container::reset();

        try {
            Container::getInstance();
            $this->fail('expected getInstance() to refuse');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('no application', strtolower($exception->getMessage()));
            $this->assertStringContainsString('app()', $exception->getMessage());
        }
    }

    public function test_has_instance_reports_whether_one_exists(): void
    {
        Container::reset();
        $this->assertFalse(Container::hasInstance());

        Container::setInstance(new Container());
        $this->assertTrue(Container::hasInstance());
    }

    public function test_an_established_container_is_handed_back_unchanged(): void
    {
        $container = new Container();
        Container::setInstance($container);

        $this->assertSame($container, Container::getInstance());
        $this->assertSame($container, Container::getInstance(), 'and the same one every time');
    }

    public function test_setting_one_returns_the_one_it_replaced(): void
    {
        $first = new Container();
        $second = new Container();

        Container::setInstance($first);

        $this->assertSame($first, Container::setInstance($second));
        $this->assertSame($second, Container::getInstance());
    }

    public function test_reset_un_establishes_it(): void
    {
        Container::setInstance(new Container());
        Container::reset();

        $this->assertFalse(Container::hasInstance());
    }

    /** The application is what establishes the container when none exists. */
    public function test_an_application_creates_and_publishes_the_first_container(): void
    {
        Container::reset();

        $app = new Application(sys_get_temp_dir());

        $this->assertTrue(Container::hasInstance());
        $this->assertSame($app->getContainer(), Container::getInstance());
    }

    /** And adopts one a host set up deliberately. */
    public function test_an_application_adopts_an_established_container(): void
    {
        $established = new Container();
        Container::setInstance($established);

        $this->assertSame($established, (new Application(sys_get_temp_dir()))->getContainer());
    }

    /** A container passed in wins over the established one. */
    public function test_an_explicit_container_wins(): void
    {
        Container::setInstance(new Container());

        $explicit = new Container();

        $this->assertSame($explicit, (new Application(sys_get_temp_dir(), $explicit))->getContainer());
    }

    /** Two calls must not quietly produce two different containers. */
    public function test_nothing_is_created_as_a_side_effect_of_asking(): void
    {
        Container::reset();

        try {
            Container::getInstance();
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse(
            Container::hasInstance(),
            'a failed lookup must not leave a container behind',
        );
    }
}
