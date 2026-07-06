<?php

namespace Nitro\View\Engine;

use Nitro\View\Support\Renderable;

/**
 * A single view instance — a template name plus its data, rendered on demand.
 */
class View implements Renderable
{
    private array $data = [];
    private ?ViewFactory $factory = null;

    public function __construct(
        private string $template,
        array $data = [],
    ) {
        $this->data = $data;
    }

    public function setFactory(ViewFactory $factory): static
    {
        $this->factory = $factory;
        return $this;
    }

    public function with(string $key, mixed $value): static
    {
        $this->data[$key] = $value;
        return $this;
    }

    public function withData(array $data): static
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }

    public function template(): string
    {
        return $this->template;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function render(): string
    {
        if (!$this->factory) {
            throw new \RuntimeException(
                'View must be created via ViewFactory::make() to support rendering.'
            );
        }

        return $this->factory->renderView($this);
    }

    public function __toString(): string
    {
        try {
            return $this->render();
        } catch (\Exception $e) {
            return '';
        }
    }
}
