<?php

namespace Nitro\View;

use Nitro\View\Contracts\View as ViewContract;
use RuntimeException;
use Throwable;

/**
 * A chosen view and its data, rendered on demand.
 */
class View implements ViewContract
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * The factory that built this view, and that will render it.
     *
     * A view constructed directly has none, and {@see render()} says so rather
     * than failing somewhere less obvious.
     */
    private ?Factory $factory = null;

    /**
     * @param string               $template The view name, such as `orders.show`.
     * @param array<string, mixed> $data
     */
    public function __construct(
        private string $template,
        array $data = [],
    ) {
        $this->data = $data;
    }

    /**
     * Attach the factory responsible for rendering this view.
     */
    public function setFactory(Factory $factory): static
    {
        $this->factory = $factory;

        return $this;
    }

    /**
     * Add a single value to the view's data.
     */
    public function with(string $key, mixed $value): static
    {
        $this->data[$key] = $value;

        return $this;
    }

    /**
     * Merge several values into the view's data.
     *
     * @param array<string, mixed> $data
     */
    public function withData(array $data): static
    {
        $this->data = array_merge($this->data, $data);

        return $this;
    }

    /**
     * The name the view was resolved under.
     */
    public function name(): string
    {
        return $this->template;
    }

    /**
     * The name the view was resolved under.
     *
     * @deprecated Use {@see name()}, which is the name the contract uses.
     */
    public function template(): string
    {
        return $this->template;
    }

    /**
     * The data the view will render with.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Render the view to markup.
     *
     * @throws RuntimeException When the view was built without a factory.
     */
    public function render(): string
    {
        if (! $this->factory) {
            throw new RuntimeException(
                'View must be created via Factory::make() to support rendering.'
            );
        }

        return $this->factory->renderView($this);
    }

    /**
     * Render the view when it is used as a string.
     *
     * Returns an empty string on failure, since a throw here surfaces only as
     * "could not be converted to string". Call render() where it matters.
     */
    public function __toString(): string
    {
        try {
            return $this->render();
        } catch (Throwable) {
            return '';
        }
    }
}
