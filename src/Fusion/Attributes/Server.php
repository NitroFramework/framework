<?php

namespace Nitro\Fusion\Attributes;

use Attribute;

/**
 * Marks a component method as a Data method — server-only. Its body is NOT
 * transpiled to JS; instead the client gets an async stub that calls an
 * auto-generated, authenticated, validated endpoint (Checksum + SecurityPolicy),
 * runs the real method through Nitro's container, and applies the returned state
 * patch. This is the boundary that keeps DB/Auth/secrets off the client.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Server
{
}
