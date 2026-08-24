<?php

namespace Tests\Unit\Exceptions;

use Nitro\Container\Container;
use Nitro\Exceptions\ExceptionHandler;
use Nitro\Exceptions\HttpException;
use Nitro\Foundation\Application;
use Nitro\View\Contracts\ViewEngine;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The production error page resolves through VIEWS, so an application can
 * replace any of them by dropping resources/views/errors/{code}.blade.php into
 * place — nothing to publish, nothing to register.
 *
 * Resolution order: errors.{code} → errors.{n}xx → nitro-errors::{code} →
 * nitro-errors::{n}xx → the built-in page.
 */
class ErrorViewTest extends TestCase
{
    private static bool $bootstrapped = false;

    public static function setUpBeforeClass(): void
    {
        if (! self::$bootstrapped) {
            require_once __DIR__ . '/../../../vendor/autoload.php';
            Application::create(dirname(__DIR__, 3))->bootstrap();
            self::$bootstrapped = true;
        }
    }

    private function handler(): ExceptionHandler
    {
        return Container::getInstance()->make(ExceptionHandler::class);
    }

    public function test_the_framework_error_view_namespace_is_registered(): void
    {
        $engine = Container::getInstance()->make(ViewEngine::class);

        $this->assertTrue($engine->viewExists('nitro-errors::404'));
        $this->assertTrue($engine->viewExists('nitro-errors::500'));
        $this->assertTrue($engine->viewExists('nitro-errors::4xx'));
        $this->assertTrue($engine->viewExists('nitro-errors::5xx'));
    }

    public function test_a_404_renders_the_404_view_not_a_generic_server_error(): void
    {
        $html = $this->handler()->render(new HttpException(404, 'No route matched'));

        $this->assertStringContainsString('404', $html);
        $this->assertStringContainsString('Page Not Found', $html);
        // The old single page told every visitor the same thing.
        $this->assertStringNotContainsString('technical difficulties', $html);
        // And it must never echo the internal message back at the user.
        $this->assertStringNotContainsString('No route matched', $html);
    }

    public function test_a_419_renders_its_own_copy(): void
    {
        $html = $this->handler()->render(new HttpException(419, 'CSRF token mismatch.'));

        $this->assertStringContainsString('Page Expired', $html);
        $this->assertStringNotContainsString('CSRF token mismatch', $html);
    }

    public function test_an_uncategorised_4xx_falls_back_to_the_4xx_view(): void
    {
        $html = $this->handler()->render(new HttpException(451, 'Unavailable For Legal Reasons'));

        $this->assertStringContainsString('451', $html);
        $this->assertStringContainsString('Check the address', $html);
    }

    public function test_a_plain_exception_renders_the_500_view(): void
    {
        $html = $this->handler()->render(new RuntimeException('database is on fire'));

        $this->assertStringContainsString('500', $html);
        $this->assertStringContainsString('Server Error', $html);
        $this->assertStringNotContainsString('database is on fire', $html);
    }
}
