<?php

namespace Nitro\Container\Attributes;

use Attribute;

/**
 * Marks a class as safe to keep for the life of the process.
 *
 * A declaration rather than a request: it asserts that the class holds no
 * request state, and the container enforces that by refusing to build it with
 * a request-scoped dependency. Binding it as scoped() is then a contradiction
 * the container reports rather than honours.
 *
 *     #[ProcessScoped]
 *     class Router { }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ProcessScoped
{
}
