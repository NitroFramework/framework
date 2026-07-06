<?php

namespace Nitro\Htmx\Attributes;

use Attribute;

/**
 * Marks a public property as bindable from the request body / query.
 *
 *   #[Modelable]
 *   public string $email = '';
 *
 * Equivalent class-level form when you'd rather list the property names
 * once instead of attributing each one:
 *
 *   protected array $modelable = ['email', 'name'];
 *
 * Without either opt-in, the framework treats public properties as
 * server-only state — auto-persisted and reflected into view data, but
 * NOT overwritten by request input. This closes the obvious tampering
 * hole when persistState is on: a request can only mutate properties
 * the component author explicitly opted in.
 *
 * Use it on every property that's the target of a hx-model input or
 * any other request-driven binding.
 *
 * The name is server-side and intentionally distinct from the client
 * attribute `hx-model` — both work together, but they live in different
 * layers (PHP vs hx-component.js).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Modelable
{
}
