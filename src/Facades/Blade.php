<?php

namespace Nitro\Facades;

/**
 * Blade facade — compiles templates and registers directives.
 *
 *   Blade::directive('money', fn ($amount) => "<?= money($amount) ?>");
 *
 * @method static string render(string $view, array $data = [])
 * @method static void directive(string $name, callable $handler)
 * @method static string compile(string $template)
 * @method static bool exists(string $view)
 */
class Blade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'blade';
    }
}
