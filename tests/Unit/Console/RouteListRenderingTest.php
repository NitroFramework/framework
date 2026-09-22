<?php

namespace Tests\Unit\Console;

use Nitro\Console\Commands\RouteListCommand;
use Nitro\Console\OutputFormatter;
use Nitro\Console\Support\Terminal;
use PHPUnit\Framework\TestCase;

/**
 * How `route:list` lays a route out.
 *
 * Each path runs its own line of dots to its own handler. Padding every column
 * to the widest value instead pushed all the handlers into one far column, so
 * a single long path left a gap across every other row.
 */
class RouteListRenderingTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('COLUMNS');
    }

    /**
     * @param array<int, array<string, string>> $rows
     */
    private function render(array $rows, int $width): string
    {
        putenv('COLUMNS=' . $width);

        $command = (new \ReflectionClass(RouteListCommand::class))->newInstanceWithoutConstructor();

        $output = new \ReflectionProperty($command, 'output');
        $output->setValue($command, new OutputFormatter(false));

        $render = new \ReflectionMethod($command, 'render');

        ob_start();
        $render->invoke($command, $rows);

        return (string) ob_get_clean();
    }

    private function route(string $method, string $uri, string $name, string $action, string $middleware = ''): array
    {
        return compact('method', 'uri', 'name', 'action', 'middleware');
    }

    public function test_the_terminal_width_is_honoured(): void
    {
        putenv('COLUMNS=64');

        $this->assertSame(64, Terminal::width(), 'COLUMNS must win over anything measured');
    }

    public function test_a_route_is_joined_to_its_handler_by_dots(): void
    {
        $out = $this->render([$this->route('GET', '/users', 'users.index', 'UserController@index')], 100);

        $this->assertStringContainsString('/users', $out);
        $this->assertStringContainsString('users.index', $out);
        $this->assertStringContainsString('..', $out, 'the leader is what makes the row scannable');
    }

    public function test_no_line_runs_past_the_terminal_width(): void
    {
        $rows = [
            $this->route('GET', '/', 'home', 'view: welcome'),
            $this->route('GET', '/a/very/long/path/that/goes/on/for/a/while', '', 'Closure at web.php:219'),
            $this->route(
                'POST',
                '/confirm-password',
                'password.confirm',
                'App\Controllers\Auth\ConfirmablePasswordController@store'
            ),
        ];

        foreach ([120, 100, 60] as $width) {
            foreach (explode("\n", $this->render($rows, $width)) as $line) {
                $this->assertLessThanOrEqual(
                    $width,
                    mb_strlen(rtrim($line, "\r")),
                    "a line ran past {$width} columns"
                );
            }
        }
    }

    /**
     * The handler is what the reader is looking for, so the name is dropped
     * before the handler is touched.
     */
    public function test_a_cramped_line_drops_the_name_before_the_handler(): void
    {
        $out = $this->render([$this->route(
            'POST',
            '/confirm-password',
            'password.confirm',
            'App\Controllers\Auth\ConfirmablePasswordController@store'
        )], 100);

        $this->assertStringContainsString('ConfirmablePasswordController@store', $out);
        $this->assertStringNotContainsString('password.confirm', $out);
    }

    /** A handler too long even alone keeps its tail, which identifies it. */
    public function test_an_overlong_handler_keeps_its_class_and_method(): void
    {
        $out = $this->render([$this->route(
            'GET',
            '/x',
            '',
            'App\Very\Deeply\Namespaced\Controllers\Admin\Reporting\MonthlyExportController@handle'
        )], 60);

        $this->assertStringContainsString('@handle', $out);
        $this->assertStringContainsString('…', $out, 'the front is what gets trimmed');
    }

    public function test_middleware_is_listed_under_its_route(): void
    {
        $out = $this->render(
            [$this->route('GET', '/', 'home', 'view: welcome', 'web, auth')],
            100
        );

        $this->assertStringContainsString('web, auth', $out);
        $this->assertStringContainsString('⇂', $out);
    }
}
