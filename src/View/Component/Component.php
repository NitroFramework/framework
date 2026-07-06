<?php

namespace Nitro\View\Component;

use Nitro\View\Component\ComponentAttributeBag;
use Nitro\View\Support\HtmlString;

/**
 * Base class for view components.
 */
abstract class Component
{
    /**
     * Default slot content.
     */
    public HtmlString $slot;

    /**
     * Named slots — available as $title, $footer etc in the view.
     *
     * @var array<string, HtmlString>
     */
    public array $slots = [];

    /**
     * Attributes not declared in @props.
     */
    public ComponentAttributeBag $attributes;

    public function __construct()
    {
        $this->slot       = new HtmlString();
        $this->attributes = new ComponentAttributeBag();
    }

    /**
     * Return the view name this component renders.
     */
    abstract public function render(): string;

    /**
     * Return public properties (excluding slot/slots/attributes) as view data.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        static $cache = [];

        $class = static::class;

        if (!isset($cache[$class])) {
            $ref   = new \ReflectionClass($this);
            $skip  = ['slot', 'slots', 'attributes'];
            $names = [];

            foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
                if (!in_array($prop->getName(), $skip, true)) {
                    $names[] = $prop->getName();
                }
            }

            $cache[$class] = $names;
        }

        $data = [];
        foreach ($cache[$class] as $name) {
            $data[$name] = $this->$name;
        }

        return $data;
    }

    /**
     * Override to pass extra computed values to the view.
     *
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [];
    }
}
