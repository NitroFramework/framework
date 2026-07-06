<?php

namespace Nitro\Http\Controller\Concerns;

use Nitro\Http\Response;

/**
 * Controller concern: render a view to an HTTP response.
 */
trait RendersViews
{

    public function view(string $view, array $data = [], string $layout = '', string $section = 'content'): Response
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
