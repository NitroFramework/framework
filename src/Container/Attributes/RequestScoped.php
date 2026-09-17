<?php

namespace Nitro\Container\Attributes;

use Attribute;

/**
 * Marks a class as holding state belonging to one request.
 *
 * The lifetime then travels with the class rather than with each place it is
 * bound, so a provider cannot register it as a singleton and a second provider
 * cannot disagree. The container refuses to hand an instance of it to anything
 * that outlives a request.
 *
 *     #[RequestScoped]
 *     class CurrentOrganisation { }
 *
 * Use it on anything that reads the request, the session, or the signed-in
 * user. A class with no marker is transient and takes the lifetime of whatever
 * consumes it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class RequestScoped
{
}
