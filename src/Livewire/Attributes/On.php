<?php

namespace Nitro\Livewire\Attributes;

use Attribute;

/**
 * Marks a component method as a listener for a dispatched event: when any
 * component on the page dispatches this event, the client calls the method with
 * the event's parameters.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class On
{
    public function __construct(public string $event) {}
}
