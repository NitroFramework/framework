<?php

use Nitro\Http\Response;
use Nitro\View\Engine\View;
use Nitro\View\Support\Htmlable;

if (!function_exists('nitro_e')) {
    /**
     * Inline HTML-escape used by compiled {{ }} echoes. A global function
     * is dispatched faster than $this->e() — PHP's opcache + Zend engine
     * optimize free-function calls more aggressively than method calls,
     * and the call doesn't require a vtable lookup.
     *
     * Htmlable instances render themselves untouched so component slots
     * and pre-rendered HtmlString fragments survive the echo without
     * double-escaping. Everything else goes through htmlspecialchars with
     * Laravel's exact flags: ENT_QUOTES | ENT_SUBSTITUTE (so invalid UTF-8
     * becomes the replacement char instead of an empty string) and
     * double-encoding on (matching e()/escape() and Laravel's Blade).
     */
    function nitro_e(mixed $value): string
    {
        if ($value instanceof Htmlable) {
            return $value->toHtml();
        }
        if ($value === null) {
            return '';
        }
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('view')) {
    /**
     * Render a view with data.
     * 
     * When called from a controller/route → returns Response (for HTTP output).
     * When cast to string (e.g. inside @widget) → returns HTML via Response::__toString().
     * 
     * @param string $view View name (e.g., 'welcome', 'dashboard.index')
     * @param array<string, mixed> $data Data to pass to the view
     * @return Response
     */
    function view(string $view, array $data = []): Response
    {
        $content = app('view')->render($view, $data);
        $response = new Response($content);
        $response->header('Content-Type', 'text/html; charset=utf-8');
        return $response;
    }
}

if (!function_exists('component')) {
    /**
     * Render a component with props
     * 
     * @param string $name Component name (auto-prefixed with 'components.')
     * @param array $props Component props
     * @return string Rendered HTML
     */
    function component(string $name, array $props = []): string
    {
        if (!str_starts_with($name, 'components.')) {
            $name = 'components.' . $name;
        }

        return app('view.factory')->renderPartial($name, $props);
    }
}