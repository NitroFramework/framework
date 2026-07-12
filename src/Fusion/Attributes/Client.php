<?php

namespace Nitro\Fusion\Attributes;

use Attribute;

/**
 * Marks a component for client-side (transpiled) execution. The Fusion build
 * discovers `#[Client]` components, transpiles their Pure-UI methods to JS, and
 * turns their `#[Server]` methods into authenticated RPC endpoints. Without it a
 * component behaves as a normal server-rendered component.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Client
{
}
