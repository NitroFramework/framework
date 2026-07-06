<?php

namespace Nitro\Htmx;

/**
 * Chainable render configuration object.
 *
 * Returned by HtmxComponent::render() to allow fluent configuration
 * of the rendering context. The kernel reads this to determine how
 * to build the final response.
 *
 * Usage:
 *
 *   // Partial render (HTMX actions, widget embedding)
 *   $this->render('students.index', $data);
 *
 *   // Full page with layout
 *   $this->render('students.index', $data)->withLayout('layouts.app');
 *
 *   // Full page with layout + custom section
 *   $this->render('students.index', $data)->withLayout('layouts.app')->withSection('main');
 *
 *   // Partial with specific fragments
 *   $this->render('students.index', $data)->withFragments(['table', 'pagination']);
 */
class RenderContext
{
    public string $view;
    public array $data;
    public ?string $layout = null;
    public string $section = 'content';
    public ?array $fragments = null;

    public function __construct(string $view, array $data = [])
    {
        $this->view = $view;
        $this->data = $data;
    }

    /**
     * Wrap the rendered view in a layout.
     *
     * Only applied for page requests — the kernel ignores this
     * for HTMX action responses automatically.
     *
     *   $this->render('students', $data)->withLayout('layouts.app');
     */
    public function withLayout(string $layout): static
    {
        $this->layout = $layout;
        return $this;
    }

    /**
     * Set the section name for layout injection.
     *
     * Defaults to 'content' if not called.
     *
     *   $this->render('students', $data)
     *        ->withLayout('layouts.app')
     *        ->withSection('main');
     */
    public function withSection(string $section): static
    {
        $this->section = $section;
        return $this;
    }

    /**
     * Render only specific @fragment blocks from the view.
     *
     *   $this->render('students', $data)->withFragments(['table', 'pagination']);
     */
    public function withFragments(array $fragments): static
    {
        $this->fragments = $fragments;
        return $this;
    }

    /**
     * Check if this context has a layout configured.
     */
    public function hasLayout(): bool
    {
        return $this->layout !== null && $this->layout !== '';
    }
}