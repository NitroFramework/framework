<?php

namespace Nitro\Http\Controller\Concerns;

use Nitro\Http\Response;

/**
 * Rendering a view to an HTTP response, as a method on the controller.
 *
 * The `view()` helper does the same and is shorter; this exists for the
 * optional layout and section arguments, which the helper does not take.
 */
trait RendersViews
{
    /**
     * Render a view, optionally into a named section of a layout.
     *
     * @param array<string, mixed> $data
     */
    protected function view(string $view, array $data = [], string $layout = '', string $section = 'content'): Response
    {
        $blade = app('view');

        if ($layout) {
            $content = $blade->render($view, $data);
            $blade->forceSection($section, $content);
            $html = $blade->getFactory()->renderPartial($layout, $data);
            return Response::html($html);
        }

        return Response::html($blade->render($view, $data));
    }
}
